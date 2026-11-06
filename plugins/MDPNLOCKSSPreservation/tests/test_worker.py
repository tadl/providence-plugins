"""Synthetic deposit/recovery tests. No CA bootstrap, network or real records."""
import copy
from datetime import datetime, timezone
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("preservation", Path(__file__).parents[1] / "worker/preservation.py")
preservation = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preservation)


class PreservationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.counter = 0
        self.snapshot = {
            "repository_id": "synthetic-installation", "collection_id": 12, "captured_at": "2026-01-02T12:00:00Z",
            "records": {"ca_collections": {"12": {"row": {"collection_id": 12, "access": 0, "idno": "duplicate"}, "labels": [{"name": "Fictional papers"}], "attributes": [], "acl": []}},
                        "ca_objects": {"1": {"row": {"object_id": 1, "idno": "duplicate", "access": 0}, "labels": [{"name": "A café"}, {"name": "Un café", "locale_id": 2}],
                                                "attributes": [{"values": [{"text": "=SUM(1,2)"}, {"text": "two\nlines"}]}], "acl": []}}},
            "relationships": {"ca_objects_x_collections": {"1": {"row": {"relation_id": 1, "object_id": 1, "collection_id": 12, "rank": 4}}}},
            "schema": {"installation_profile_xml": "<profile><profileName>Synthetic</profileName></profile>", "definitions": {"ca_locales": {"2": "fr_FR"}}},
            "external_references": [], "files": [],
        }

    def tearDown(self):
        self.temp.cleanup()

    def original(self, key, content, name="A photo.tif"):
        source = self.root / (hashlib.sha256(key.encode()).hexdigest() + ".source")
        source.write_bytes(content)
        return {"key": key, "source_path": str(source), "original_filename": name,
                "owner": {"table": "ca_object_representations", "id": key}, "expected_md5": hashlib.md5(content).hexdigest()}

    def build(self, snapshot=None, mode="full", parent=None, limit=5_000_000, metadata=None, revision=None):
        self.counter += 1
        output = self.root / ("job" + str(self.counter))
        output.mkdir()
        job = {"id": str(self.counter).zfill(32), "revision": self.counter if revision is None else revision, "mode": mode,
               "parent_id": parent["snapshot"].get("deposit_id") if mode == "supplemental" and parent else None}
        request = {"snapshot": snapshot or self.snapshot, "job": job, "parent": parent, "output_directory": str(output),
                   "bag_group_identifier": "EXAMPLE_synthetic_C12", "organization_code": "EXAMPLE", "part_size_bytes": limit,
                   "bag_metadata": metadata if metadata is not None else {"Contact-Name": "Example Staff", "Contact-Email": "preservation@example.org", "Source-Organization": "Example Archive"}}
        return preservation.build(request), output

    def archives(self, result, output):
        return [output / p["filename"] for p in result["parts"]]

    def assert_independently_valid(self, path):
        # Independent test oracle: inspect standard tar records and digest their
        # bytes, rather than relying on the worker's validator or staging files.
        with tarfile.open(path) as tar:
            members = {m.name: m for m in tar if m.isfile()}
            root = next(iter(members)).split("/")[0]
            self.assertEqual(tar.extractfile(root + "/bagit.txt").read().decode(), "BagIt-Version: 1.0\nTag-File-Character-Encoding: UTF-8\n")
            listed = set()
            for manifest in ("manifest-sha256.txt", "tagmanifest-sha256.txt"):
                for line in tar.extractfile(root + "/" + manifest).read().decode().splitlines():
                    checksum, relative = line.split("  ", 1)
                    content = tar.extractfile(root + "/" + relative).read()
                    self.assertEqual(checksum, hashlib.sha256(content).hexdigest())
                    listed.add(root + "/" + relative)
            self.assertEqual(set(members), listed | {root + "/tagmanifest-sha256.txt"})
            return tar.extractfile(root + "/bag-info.txt").read().decode()

    def test_full_deposit_retains_private_metadata_and_duplicate_filenames(self):
        self.snapshot["files"] = [self.original("1", b"original A"), self.original("2", b"original B")]
        result, output = self.build()
        paths = self.archives(result, output)
        tags = self.assert_independently_valid(paths[0])
        self.assertIn("Bag-Count: 1 of 1", tags)
        self.assertIn("Bag-Group-Identifier: EXAMPLE_synthetic_C12", tags)
        self.assertNotIn("Preservation-", tags)
        self.assertEqual(len({f["path"] for f in result["snapshot"]["files"]}), 2)
        snapshot = preservation.verify_archive(paths[0])["snapshot"]
        self.assertEqual(snapshot["records"], self.snapshot["records"])
        self.assertNotIn("source_path", json.dumps(snapshot))
        self.assertFalse((output / "staging").exists())

    def test_compact_names_use_collection_revision_and_part_numbers(self):
        self.mdpn_profile()
        self.snapshot["files"] = [self.original("1", b"a" * 900_000), self.original("2", b"b" * 900_000)]
        full, output = self.build(metadata=self.mdpn_metadata())
        self.assertEqual(full["parts"][0]["filename"], "EXAMPLE_C12_001.tar")
        self.assertNotIn(full["snapshot"]["deposit_id"], full["parts"][0]["filename"])
        multipart, parts_output = self.build(limit=1_300_000, metadata=self.mdpn_metadata())
        self.assertEqual([p["filename"] for p in multipart["parts"]], ["EXAMPLE_C12_002_P001.tar", "EXAMPLE_C12_002_P002.tar"])
        update = copy.deepcopy(self.snapshot)
        update["records"]["ca_collections"]["12"]["labels"][0]["name"] = "A very long fictional collection title " * 100
        supplement, supplement_output = self.build(update, "supplemental", multipart, metadata=self.mdpn_metadata())
        self.assertEqual(supplement["parts"][0]["filename"], "EXAMPLE_C12_003.tar")
        archives = self.archives(multipart, parts_output) + self.archives(supplement, supplement_output)
        for archive in self.archives(full, output) + archives:
            lines = self.assert_independently_valid(archive).splitlines()
            self.assertEqual(lines[10], "External-Identifier: " + archive.stem)
        preservation.recover(archives, supplement["snapshot"]["deposit_id"], self.root / "compact-recovered")
        large_revision, _ = self.build(metadata=self.mdpn_metadata(), revision=1000)
        self.assertEqual(large_revision["parts"][0]["filename"], "EXAMPLE_C12_1000.tar")

    def test_invalid_deposit_numbers_fail_before_staging(self):
        for revision in (0, -1, True, 1.5, "1"):
            with self.subTest(revision=revision):
                with self.assertRaisesRegex(ValueError, "preservation revision"):
                    self.build(revision=revision)
                self.assertFalse((self.root / ("job" + str(self.counter)) / "staging").exists())

    def test_single_full_then_multipart_incremental_reconstructs_all_changes(self):
        self.mdpn_profile()
        self.snapshot["files"] = [self.original("1", b"unchanged original")]
        full, output = self.build(metadata=self.mdpn_metadata())
        self.assertEqual(full["parts"][0]["filename"], "EXAMPLE_C12_001.tar")
        update = copy.deepcopy(self.snapshot)
        update["files"] += [self.original("2", b"a" * 900_000), self.original("3", b"b" * 900_000)]
        supplement, supplement_output = self.build(update, "supplemental", full, limit=1_300_000, metadata=self.mdpn_metadata())
        self.assertEqual([p["filename"] for p in supplement["parts"]], ["EXAMPLE_C12_002_P001.tar", "EXAMPLE_C12_002_P002.tar"])
        self.assertEqual(supplement["snapshot"]["files"][0]["bag_id"], full["parts"][0]["bag_id"])
        archives = self.archives(full, output) + self.archives(supplement, supplement_output)
        for number, archive in enumerate(archives[1:], 1):
            tags = dict(line.split(": ", 1) for line in self.assert_independently_valid(archive).splitlines())
            self.assertEqual(tags["Bag-Count"], str(number) + " of 2")
            self.assertEqual(tags["External-Identifier"], archive.stem)
            self.assertEqual(tags["Bag-Group-Identifier"], "")
        with self.assertRaisesRegex(ValueError, "required recovery bag"):
            preservation.recover(archives[:-1], supplement["snapshot"]["deposit_id"], self.root / "missing-incremental-part")
        preservation.recover(archives, supplement["snapshot"]["deposit_id"], self.root / "multipart-incremental-recovered")
        originals = [p.read_bytes() for p in (self.root / "multipart-incremental-recovered").rglob("*") if p.is_file() and "originals" in p.parts]
        self.assertEqual(sorted(originals), [b"a" * 900_000, b"b" * 900_000, b"unchanged original"])

    def mdpn_profile(self):
        profile = json.loads((Path(__file__).parent / "fixtures/mdpn-profile.json").read_text())
        self.snapshot["schema"]["bagit_profile"] = profile
        return profile

    def mdpn_metadata(self):
        return {"Source-Organization": "Example Member Archive", "Contact-Name": "Example Staff",
                "Contact-Email": "preservation@example.org"}

    def test_canonical_optional_defaults_and_value_restrictions(self):
        profile = self.mdpn_profile()
        profile["bag_info_order"].append("Transfer-Policy")
        profile["bag_info_defaults"] = {"Transfer-Policy": "Example policy"}
        profile["tag_values"] = {"Transfer-Policy": ["Example policy"], "Contact-Name": ["Example Staff"]}
        result, output = self.build(metadata=self.mdpn_metadata())
        with tarfile.open(self.archives(result, output)[0]) as archive:
            tags = archive.extractfile(result["parts"][0]["bag_id"] + "/bag-info.txt").read().decode()
        self.assertIn("Transfer-Policy: Example policy\n", tags)
        self.assert_independently_valid(self.archives(result, output)[0])
        invalid = self.mdpn_metadata()
        invalid["Contact-Name"] = "Unsupported contact"
        with self.assertRaisesRegex(ValueError, "tag value is not allowed"):
            self.build(metadata=invalid)

    def test_saved_bag_info_matches_every_bag_and_retains_both_descriptions(self):
        self.snapshot["files"] = [self.original("1", b"a" * 900_000), self.original("2", b"b" * 900_000)]
        for mdpn in (False, True):
            if mdpn:
                self.mdpn_profile()
            metadata = self.mdpn_metadata()
            metadata.update({"External-Description": "Short fictional summary.",
                             "Internal-Sender-Description": "Fictional collection. " + "Complete narrative. " * 200 + "Final words."})
            for limit in (5_000_000, 1_300_000):
                with self.subTest(mdpn=mdpn, limit=limit):
                    result, output = self.build(metadata=metadata, limit=limit)
                    self.assertEqual(set(result["bag_info"]), {p["bag_id"] for p in result["parts"]})
                    for archive in self.archives(result, output):
                        text = self.assert_independently_valid(archive)
                        self.assertEqual(result["bag_info"][archive.stem], text)
                        tags = dict(line.split(": ", 1) for line in text.splitlines())
                        self.assertEqual(tags["External-Description"], metadata["External-Description"])
                        self.assertEqual(tags["Internal-Sender-Description"], metadata["Internal-Sender-Description"])
                        if mdpn:
                            self.assertEqual(text.splitlines()[10], "External-Identifier: " + archive.stem)

    def test_mdpn_bag_info_matches_dart3_profile_layout_with_or_without_description(self):
        reference = json.loads((Path(__file__).parent / "fixtures/dart3-mdpn.json").read_text())
        profile = self.mdpn_profile()
        self.snapshot["files"] = [self.original("1", b"synthetic uploaded original")]
        for description in (None, "Fictional papers", "  Fictional   papers  ", "Fictional narrative. " * 200):
            with self.subTest(description=description):
                metadata = self.mdpn_metadata()
                if description is not None:
                    metadata["External-Description"] = description
                with patch.object(preservation, "datetime") as clock:
                    clock.now.return_value = datetime(2026, 1, 3, 14, 15, 16, tzinfo=timezone.utc)
                    result, output = self.build(metadata=metadata)
                archive = self.archives(result, output)[0]
                bag_info = self.assert_independently_valid(archive)
                lines = bag_info.splitlines()
                tags = dict(line.split(": ", 1) for line in lines)
                self.assertEqual([line.split(": ", 1)[0] for line in lines], reference["bag_info_fields"])
                # This is the receiver's actual positional heading lookup.
                self.assertEqual(lines[10].split(" ", 1)[1].strip(), result["parts"][0]["bag_id"])
                for name in reference["blank_fields"]:
                    self.assertEqual(tags[name], "")
                clean_description = " ".join((description or "").split())
                self.assertEqual(tags["External-Description"], clean_description)
                self.assertEqual(tags["BagIt-Profile-Identifier"], reference["profile_identifier"])
                self.assertEqual(tags["Bagging-Date"], "2026-01-03T14:15:16Z")
                self.assertEqual(tags["Bagging-Software"], preservation.SOFTWARE)
                self.assertTrue(tags["Bagging-Software"].startswith("MDPNLOCKSSPreservation/"))
                self.assertFalse(any(name.startswith("Preservation-") for name in tags))
                with tarfile.open(archive) as tar:
                    payloads = [m for m in tar if m.isfile() and "/data/" in m.name]
                    payload_bytes = sum(m.size for m in payloads)
                self.assertEqual(tags["Payload-Oxum"], f"{payload_bytes}.{len(payloads)}")
                self.assertTrue(1024 <= payload_bytes < 1024 ** 2)
                self.assertEqual(tags["Bag-Size"], f"{payload_bytes / 1024:.1f} kB")
                expected = "\n".join(reference["bag_info_template"]).format(
                    size=f"{payload_bytes / 1024:.1f} kB", software=preservation.SOFTWARE,
                    description=clean_description, bag_id=archive.stem, oxum=f"{payload_bytes}.{len(payloads)}") + "\n"
                self.assertEqual(bag_info, expected)
                preservation.recover([archive], result["snapshot"]["deposit_id"], self.root / ("mdpn-recovered" + str(self.counter)))
        for size, expected in reference["payload_size_examples"].items():
            self.assertEqual(preservation.dart3_bag_info({}, profile, int(size))["Bag-Size"], expected)

    def test_mdpn_multipart_and_supplement_keep_dart3_layout_and_recovery(self):
        self.mdpn_profile()
        self.snapshot["files"] = [self.original("1", b"a" * 900_000), self.original("2", b"b" * 900_000)]
        full, output = self.build(limit=1_300_000, metadata=self.mdpn_metadata())
        self.assertEqual(len(full["parts"]), 2)
        update = copy.deepcopy(self.snapshot)
        update["records"]["ca_objects"]["1"]["labels"][0]["name"] = "Revised fictional title"
        supplement, supplement_output = self.build(update, "supplemental", full, metadata=self.mdpn_metadata())
        archives = self.archives(full, output) + self.archives(supplement, supplement_output)
        for archive in archives:
            lines = self.assert_independently_valid(archive).splitlines()
            self.assertEqual(lines[10], "External-Identifier: " + archive.stem)
        tags = dict(line.split(": ", 1) for line in self.assert_independently_valid(archives[-1]).splitlines())
        self.assertFalse(any(name.startswith("Preservation-") for name in tags))
        self.assertEqual(supplement["snapshot"]["parent_deposit_id"], full["snapshot"]["deposit_id"])
        preservation.recover(archives, supplement["snapshot"]["deposit_id"], self.root / "mdpn-chain-recovered")

    def rewrite_bag_info(self, archive, suffix):
        # Construct legacy/tampered fixtures with independently valid manifests.
        with tarfile.open(archive) as tar:
            members = tar.getmembers()
            content = {m.name: tar.extractfile(m).read() for m in members if m.isfile()}
        root = next(iter(content)).split("/")[0]
        content[root + "/bag-info.txt"] += suffix.encode()
        content[root + "/tagmanifest-sha256.txt"] = b"".join(
            hashlib.sha256(content[root + "/" + name]).hexdigest().encode() + b"  " + name.encode() + b"\n"
            for name in ("bagit.txt", "bag-info.txt", "manifest-sha256.txt"))
        with tarfile.open(archive, "w", format=tarfile.PAX_FORMAT) as tar:
            for member in members:
                data = content.get(member.name)
                if data is not None:
                    member.size = len(data)
                tar.addfile(member, io.BytesIO(data) if data is not None else None)

    def test_legacy_mdpn_bags_still_validate_and_recover(self):
        profile = self.mdpn_profile()
        del profile["bag_info_order"]
        del profile["bagit_profile_identifier"]
        result, output = self.build(metadata=self.mdpn_metadata())
        archive = self.archives(result, output)[0]
        self.rewrite_bag_info(archive, "Preservation-Deposit-Identifier: " + result["snapshot"]["deposit_id"]
                              + "\nPreservation-Mode: full\nPreservation-Revision: " + str(result["snapshot"]["revision"]) + "\n")
        tags = self.assert_independently_valid(archive)
        self.assertNotIn("Contact-Phone:", tags)
        self.assertIn("Bagging-Date: 2026-01-02\n", tags)
        preservation.recover([archive], result["snapshot"]["deposit_id"], self.root / "legacy-mdpn-recovered")
        result["parts"][0]["sha256"] = hashlib.sha256(archive.read_bytes()).hexdigest()
        self.mdpn_profile()
        update = copy.deepcopy(self.snapshot)
        update["records"]["ca_objects"]["1"]["labels"][0]["name"] = "Revised fictional title"
        supplement, supplement_output = self.build(update, "supplemental", result, metadata=self.mdpn_metadata())
        preservation.recover([archive] + self.archives(supplement, supplement_output), supplement["snapshot"]["deposit_id"],
                             self.root / "legacy-to-new-mdpn-recovered")

    def test_inconsistent_legacy_preservation_tags_are_rejected(self):
        result, output = self.build()
        archive = self.archives(result, output)[0]
        self.rewrite_bag_info(archive, "Preservation-Deposit-Identifier: " + "f" * 32 + "\n")
        self.assert_independently_valid(archive)
        with self.assertRaisesRegex(ValueError, "preservation metadata disagree"):
            preservation.verify_archive(archive)

    def test_multipart_full_and_supplements_reconstruct_latest_state(self):
        self.snapshot["files"] = [self.original("1", b"a" * 900_000), self.original("2", b"b" * 900_000)]
        full, output = self.build(limit=1_300_000)
        self.assertEqual(len(full["parts"]), 2)
        all_archives = self.archives(full, output)
        for i, path in enumerate(all_archives, 1):
            self.assertIn("Bag-Count: " + str(i) + " of 2", self.assert_independently_valid(path))
        update = copy.deepcopy(self.snapshot)
        update["files"] = [self.snapshot["files"][0], self.original("3", b"c" * 100)]
        update["records"]["ca_objects"]["1"]["labels"][0]["name"] = "Revised title"
        update["relationships"] = {}
        incremental, incremental_output = self.build(update, "supplemental", full)
        self.assertEqual(len(incremental["parts"]), 1)
        with tarfile.open(self.archives(incremental, incremental_output)[0]) as tar:
            self.assertEqual(len([m for m in tar if m.isfile() and "/originals/" in m.name]), 1)
        self.assertEqual(incremental["snapshot"]["files"][0]["bag_id"], full["snapshot"]["files"][0]["bag_id"])
        all_archives += self.archives(incremental, incremental_output)
        update2 = copy.deepcopy(update)
        update2["files"][0] = self.original("1-replacement", b"replacement")
        latest, latest_output = self.build(update2, "supplemental", incremental)
        all_archives += self.archives(latest, latest_output)
        recovery = self.root / "recovered"
        preservation.recover(all_archives, latest["snapshot"]["deposit_id"], recovery)
        restored = json.loads((recovery / "catalogue-snapshot.json").read_text())
        self.assertEqual(restored["records"], update2["records"])
        self.assertEqual(restored["relationships"], {})
        self.assertEqual(sorted(p.read_bytes() for p in recovery.rglob("*") if p.is_file() and "originals" in p.parts), [b"c" * 100, b"replacement"])
        self.assertFalse((recovery / "RECOVERY_INCOMPLETE.txt").exists())

    def test_new_full_is_independent_and_scans_detect_metadata_only_changes(self):
        self.snapshot["files"] = [self.original("1", b"a")]
        full, output = self.build()
        scan, _ = self.build(mode="scan", parent=full)
        self.assertFalse(scan["changed"])
        update = copy.deepcopy(self.snapshot)
        update["records"]["ca_objects"]["1"]["attributes"].append({"values": [{"text": "new note"}]})
        scan, _ = self.build(update, "scan", full)
        self.assertTrue(scan["changed"])
        new_full, new_output = self.build(update, "full", full)
        self.assertEqual(len(new_full["snapshot"]["chain"]), 1)
        preservation.recover(self.archives(new_full, new_output), new_full["snapshot"]["deposit_id"], self.root / "independent")

    def test_packaging_profile_changes_do_not_affect_freshness_or_weaken_integrity(self):
        profile = self.mdpn_profile()
        self.snapshot["files"] = [self.original("1", b"unchanged original")]
        full, _ = self.build(metadata=self.mdpn_metadata())
        baseline = self.root / "baseline.json"
        baseline.write_bytes(preservation.encoded(full))
        original_baseline = baseline.read_bytes()
        request = {"baselines": {full["snapshot"]["deposit_id"]: str(baseline)}}
        updates = {"source": "https://example.org/canonical-settings.json", "source_profile_version": "1.4.0",
                   "source_profile_sha256": "f" * 64, "required_tags": list(reversed(profile["required_tags"])),
                   "tag_values": {"Contact-Name": ["Example Staff"]}, "absent_profile": None}
        for field, value in updates.items():
            with self.subTest(field=field):
                update = copy.deepcopy(self.snapshot)
                if field == "absent_profile":
                    del update["schema"]["bagit_profile"]
                else:
                    update["schema"]["bagit_profile"][field] = value
                compared = preservation.compare_report(dict(request, snapshot=update))["comparisons"]
                self.assertEqual(compared[full["snapshot"]["deposit_id"]], {"current": True, "recovery_context_changed": True, "changes": {}})
                scan, _ = self.build(update, "scan", full)
                self.assertFalse(scan["changed"])
                self.assertEqual(scan["snapshot"]["changes"], [])
                self.assertNotEqual(scan["snapshot"]["fingerprint"], full["snapshot"]["fingerprint"])
                self.assertEqual(baseline.read_bytes(), original_baseline)
        tampered = copy.deepcopy(full)
        tampered["snapshot"]["schema"]["bagit_profile"]["source"] = "https://example.org/tampered.json"
        baseline.write_bytes(preservation.encoded(tampered))
        with self.assertRaisesRegex(ValueError, "baseline is inconsistent or corrupt"):
            preservation.compare_report(dict(request, snapshot=self.snapshot))

    def test_packaging_changes_require_content_changes_for_a_supplement(self):
        self.mdpn_profile()
        self.snapshot["files"] = [self.original("1", b"unchanged original")]
        full, output = self.build(metadata=self.mdpn_metadata())
        update = copy.deepcopy(self.snapshot)
        update["schema"]["bagit_profile"]["source_profile_version"] = "1.4.0"
        unchanged, unchanged_output = self.build(update, "supplemental", full, metadata=self.mdpn_metadata())
        self.assertFalse(unchanged["changed"])
        self.assertEqual(unchanged["parts"], [])
        self.assertFalse(list(unchanged_output.iterdir()))
        new_full, new_output = self.build(update, "full", full, metadata=self.mdpn_metadata())
        new_archive = self.archives(new_full, new_output)[0]
        self.assertEqual(preservation.verify_archive(new_archive)["snapshot"]["schema"], update["schema"])
        self.assertNotEqual(new_full["snapshot"]["fingerprint"], full["snapshot"]["fingerprint"])
        update["records"]["ca_objects"]["1"]["labels"][0]["name"] = "Revised fictional title"
        supplement, supplement_output = self.build(update, "supplemental", full, metadata=self.mdpn_metadata())
        self.assertEqual(supplement["snapshot"]["changes"], [{"category": "records", "key": "ca_objects:1", "operation": "changed"}])
        archives = self.archives(full, output) + self.archives(supplement, supplement_output)
        recovered = self.root / "policy-change-recovered"
        preservation.recover(archives, supplement["snapshot"]["deposit_id"], recovered)
        restored = json.loads((recovered / "catalogue-snapshot.json").read_text())
        self.assertEqual(restored["records"], update["records"])
        self.assertEqual(restored["schema"], update["schema"])
        self.assertEqual([f.read_bytes() for f in recovered.rglob("*") if f.is_file() and "originals" in f.parts], [b"unchanged original"])

    def test_catalogue_changes_remain_stale_after_a_packaging_update(self):
        self.mdpn_profile()
        self.snapshot["files"] = [self.original("1", b"original A")]
        full, _ = self.build(metadata=self.mdpn_metadata())
        baseline = self.root / "baseline.json"
        baseline.write_bytes(preservation.encoded(full))
        for category in ("records", "relationships", "definitions", "installation_profile_xml", "external_references", "files"):
            with self.subTest(category=category):
                update = copy.deepcopy(self.snapshot)
                update["schema"]["bagit_profile"]["source_profile_version"] = "1.4.0"
                if category == "records":
                    update["records"]["ca_objects"]["1"]["labels"][0]["name"] = "Revised fictional title"
                elif category == "relationships":
                    update["relationships"] = {}
                elif category == "definitions":
                    update["schema"]["definitions"]["ca_locales"]["2"] = "fr_CA"
                elif category == "installation_profile_xml":
                    update["schema"][category] = "<profile><profileName>Revised synthetic profile</profileName></profile>"
                elif category == "external_references":
                    update[category] = [{"table": "ca_collections", "id": 99}]
                else:
                    update["files"] = [self.original("1", b"original B")]
                comparison = preservation.compare_report({"snapshot": update, "baselines": {full["snapshot"]["deposit_id"]: str(baseline)}})
                contextual = category in ("installation_profile_xml", "external_references")
                checked = comparison["comparisons"][full["snapshot"]["deposit_id"]]
                self.assertEqual(checked["current"], contextual)
                self.assertEqual(checked["recovery_context_changed"], contextual)
                expected = "schema" if category == "definitions" else category
                operation = "removed" if category == "relationships" else "changed"
                self.assertEqual(checked["changes"], {} if contextual else {expected + "_" + operation: 1})
                scan, _ = self.build(update, "scan", full)
                self.assertEqual(scan["changed"], not contextual)

    def meaning_fixture(self):
        self.snapshot["records"]["ca_objects"]["1"]["attributes"] = [{"row": {"element_id": 10}, "values": [{"element_id": 11, "item_id": 2}]}]
        self.snapshot["records"]["ca_entities"] = {"30": {"row": {"entity_id": 30}, "attributes": []}}
        self.snapshot["relationships"]["ca_objects_x_collections"]["1"]["row"]["type_id"] = 5
        self.snapshot["schema"]["definitions"].update({
            "ca_metadata_elements": {"10": {"element_id": 10, "element_code": "container", "datatype": 0},
                                     "11": {"element_id": 11, "element_code": "subject", "datatype": 3, "parent_id": 10, "list_id": 1, "settings": "synthetic settings"}},
            "ca_metadata_element_labels": {"1": {"element_id": 11, "locale_id": 2, "name": "Subject"}},
            "ca_lists": {"1": {"list_id": 1, "list_code": "subjects"}},
            "ca_list_items": {"2": {"item_id": 2, "list_id": 1, "idno": "history", "item_value": "history"}},
            "ca_list_item_labels": {"1": {"item_id": 2, "locale_id": 2, "name_singular": "History"},
                                    "2": {"item_id": 99, "locale_id": 2, "name_singular": "Unused term"}},
            "ca_relationship_types": {"5": {"type_id": 5, "type_code": "member"}},
            "ca_relationship_type_labels": {"1": {"type_id": 5, "locale_id": 2, "typename": "Member"}},
        })
        self.snapshot["schema"]["tables"] = {"ca_objects_x_collections": {"foreign_keys": {
            "type_id": {"many_table_field": "type_id", "one_table": "ca_relationship_types"}}}}

    def test_installation_and_related_person_changes_do_not_invalidate_collection(self):
        self.meaning_fixture()
        full, _ = self.build()
        baseline = self.root / "baseline.json"
        baseline.write_bytes(preservation.encoded(full))
        for context in ("editor", "field_settings", "unused_term", "person_subject", "tree_indexes"):
            with self.subTest(context=context):
                update = copy.deepcopy(self.snapshot)
                if context == "editor":
                    update["schema"]["installation_profile_xml"] = "<profile><userInterfaces>New editor</userInterfaces></profile>"
                elif context == "field_settings":
                    update["schema"]["definitions"]["ca_metadata_elements"]["11"]["settings"] = "changed display and validation settings"
                    update["schema"]["definitions"]["ca_metadata_type_restrictions"] = {"99": {"element_id": 11, "type_id": 99}}
                elif context == "unused_term":
                    update["schema"]["definitions"]["ca_list_item_labels"]["2"]["name_singular"] = "Changed unused term"
                    update["schema"]["definitions"]["ca_relationship_types"]["99"] = {"type_id": 99, "type_code": "unused_relationship"}
                elif context == "person_subject":
                    update["records"]["ca_entities"]["30"]["attributes"] = [{"values": [{"element_id": 99, "item_id": 99}]}]
                else:
                    update["records"]["ca_objects"]["1"]["row"].update({"hier_left": 40, "hier_right": 41, "view_count": 500})
                compared = preservation.compare_report({"snapshot": update, "baselines": {full["snapshot"]["deposit_id"]: str(baseline)}})
                checked = compared["comparisons"][full["snapshot"]["deposit_id"]]
                self.assertTrue(checked["current"])
                self.assertTrue(checked["recovery_context_changed"])
                self.assertEqual(checked["changes"], {})
                scan, _ = self.build(update, "scan", full)
                self.assertFalse(scan["changed"])
                unchanged, _ = self.build(update, "supplemental", full)
                self.assertFalse(unchanged["parts"])

    def test_used_metadata_definitions_and_relationship_names_are_meaningful(self):
        self.meaning_fixture()
        full, _ = self.build()
        baseline = self.root / "baseline.json"
        baseline.write_bytes(preservation.encoded(full))
        for table, key, field, value in (
            ("ca_metadata_elements", "11", "datatype", 1),
            ("ca_metadata_elements", "11", "element_code", "redefined_subject"),
            ("ca_metadata_elements", "10", "element_code", "redefined_container"),
            ("ca_metadata_element_labels", "1", "name", "Revised field meaning"),
            ("ca_list_items", "2", "item_value", "different meaning"),
            ("ca_list_item_labels", "1", "name_singular", "Changed used subject"),
            ("ca_relationship_types", "5", "type_code", "different_relation"),
            ("ca_relationship_type_labels", "1", "typename", "Different relationship meaning"),
        ):
            with self.subTest(table=table, field=field):
                update = copy.deepcopy(self.snapshot)
                update["schema"]["definitions"][table][key][field] = value
                compared = preservation.compare_report({"snapshot": update, "baselines": {full["snapshot"]["deposit_id"]: str(baseline)}})
                checked = compared["comparisons"][full["snapshot"]["deposit_id"]]
                self.assertFalse(checked["current"])
                self.assertFalse(checked["recovery_context_changed"])
                self.assertEqual(checked["changes"], {"schema_changed": 1})

    def test_object_and_representation_additions_edits_and_removals_require_attention(self):
        self.snapshot["records"]["ca_object_representations"] = {"20": {"row": {"representation_id": 20}, "labels": []}}
        full, _ = self.build()
        for table in ("ca_collections", "ca_objects", "ca_object_representations"):
            for operation in ("added", "changed", "removed"):
                with self.subTest(table=table, operation=operation):
                    update = copy.deepcopy(self.snapshot)
                    key = next(iter(update["records"][table]))
                    if operation == "added":
                        update["records"][table]["999"] = copy.deepcopy(update["records"][table][key])
                    elif operation == "removed":
                        del update["records"][table][key]
                    else:
                        update["records"][table][key]["labels"].append({"name": "Revised metadata"})
                    scan, _ = self.build(update, "scan", full)
                    self.assertTrue(scan["changed"])
                    self.assertIn({"category": "records", "key": table + ":" + ("999" if operation == "added" else key), "operation": operation}, scan["snapshot"]["changes"])
        update = copy.deepcopy(self.snapshot)
        update["relationships"]["ca_objects_x_entities"] = {"50": {"row": {"object_id": 1, "entity_id": 30}}}
        scan, _ = self.build(update, "scan", full)
        self.assertTrue(scan["changed"])
        self.assertIn({"category": "relationships", "key": "ca_objects_x_entities:50", "operation": "added"}, scan["snapshot"]["changes"])

    def test_metadata_only_supplement_still_contains_complete_snapshot(self):
        full, _ = self.build()
        update = copy.deepcopy(self.snapshot)
        update["records"]["ca_objects"]["1"]["row"]["idno"] = "changed"
        supplement, output = self.build(update, "supplemental", full)
        self.assertEqual(supplement["snapshot"]["files"], [])
        self.assertEqual(preservation.verify_archive(self.archives(supplement, output)[0])["snapshot"]["records"], update["records"])

    def test_missing_part_and_baseline_fail_before_recovery_writes(self):
        self.snapshot["files"] = [self.original("1", b"a" * 900_000), self.original("2", b"b" * 900_000)]
        full, output = self.build(limit=1_300_000)
        with self.assertRaisesRegex(ValueError, "required recovery bag"):
            preservation.recover(self.archives(full, output)[:1], full["snapshot"]["deposit_id"], self.root / "missing")
        self.assertFalse((self.root / "missing").exists())
        with self.assertRaisesRegex(ValueError, "requires a successful baseline"):
            self.build(mode="supplemental")

    def test_source_checksum_mismatch_and_symlinks_fail(self):
        original = self.original("1", b"changed")
        original["expected_md5"] = "0" * 32
        self.snapshot["files"] = [original]
        with self.assertRaisesRegex(ValueError, "recorded checksum"):
            self.build()
        link = self.root / "link"
        link.symlink_to(original["source_path"])
        original["source_path"] = str(link)
        with self.assertRaisesRegex(ValueError, "absolute regular file"):
            self.build()

    def test_source_changed_during_capture_fails(self):
        original = self.original("1", b"before")
        source = Path(original["source_path"])
        native_fstat = preservation.os.fstat
        calls = 0
        def changed_fstat(fd):
            nonlocal calls
            calls += 1
            result = native_fstat(fd)
            if calls == 2:
                source.write_bytes(b"after and different size")
            return result
        with patch.object(preservation.os, "fstat", changed_fstat):
            with self.assertRaisesRegex(ValueError, "changed during capture"):
                preservation.read_original(original)

    def test_corrupt_duplicate_and_unsafe_members_are_rejected(self):
        full, output = self.build()
        original = self.archives(full, output)[0]
        for name, kind in (("../escape", "file"), ("bag/link", "link")):
            bad = self.root / (kind + ".tar")
            with tarfile.open(bad, "w") as tar:
                member = tarfile.TarInfo(name)
                if kind == "link":
                    member.type = tarfile.SYMTYPE
                    member.linkname = "/etc/passwd"
                tar.addfile(member)
            with self.assertRaisesRegex(ValueError, "Unsafe tar member"):
                preservation.verify_archive(bad)
        with tarfile.open(original, "a") as tar:
            member = tarfile.TarInfo(full["parts"][0]["bag_id"] + "/bagit.txt")
            member.size = 7
            tar.addfile(member, io.BytesIO(b"corrupt"))
        with self.assertRaisesRegex(ValueError, "duplicate tar members"):
            preservation.verify_archive(original)

    def test_php_empty_maps_and_binary_filenames(self):
        self.snapshot["relationships"] = []
        self.snapshot["schema"]["definitions"] = []
        self.snapshot["schema"]["tables"] = []
        original = self.original("1", b"a")
        original["original_filename"] = {"$base64": "/2EudGlm"}
        self.snapshot["files"] = [original]
        result, output = self.build()
        self.assertEqual(preservation.verify_archive(self.archives(result, output)[0])["snapshot"]["files"][0]["original_filename"], original["original_filename"])

    def test_unchanged_supplement_succeeds_without_a_bag_but_full_disks_fail(self):
        self.snapshot["files"] = [self.original("1", b"unchanged original")]
        full, _ = self.build()
        unchanged, output = self.build(mode="supplemental", parent=full)
        self.assertFalse(unchanged["changed"])
        self.assertEqual(unchanged["parts"], [])
        self.assertEqual(unchanged["bag_info"], {})
        self.assertEqual(unchanged["snapshot"]["changes"], [])
        self.assertEqual(unchanged["snapshot"]["compared_to_deposit_id"], full["snapshot"]["deposit_id"])
        self.assertFalse(list(output.iterdir()))
        class FullDisk:
            free = 0
        with patch.object(preservation.shutil, "disk_usage", return_value=FullDisk()):
            with self.assertRaisesRegex(ValueError, "Insufficient free staging space"):
                self.build()

    def test_used_definition_supplement_preserves_meaning_and_recovery(self):
        self.snapshot["files"] = [self.original("1", b"unchanged original")]
        full, output = self.build()
        update = copy.deepcopy(self.snapshot)
        update["schema"]["definitions"]["ca_locales"]["2"] = "fr_CA"
        supplement, supplement_output = self.build(update, "supplemental", full)
        self.assertTrue(supplement["changed"])
        self.assertEqual(supplement["snapshot"]["changes"], [{"category": "schema", "key": "metadata-schema", "operation": "changed"}])
        archive = self.archives(supplement, supplement_output)[0]
        with tarfile.open(archive) as bag:
            self.assertFalse(any('/originals/' in member.name for member in bag if member.isfile()))
        recovered = self.root / "configuration-recovered"
        preservation.recover(self.archives(full, output) + [archive], supplement["snapshot"]["deposit_id"], recovered)
        restored = json.loads((recovered / "catalogue-snapshot.json").read_text())
        self.assertEqual(restored["schema"], update["schema"])
        self.assertEqual(restored["records"], self.snapshot["records"])

    def test_valid_bagit_checksums_cannot_hide_a_wrong_file_inventory(self):
        self.snapshot["files"] = [self.original("1", b"a")]
        full, output = self.build()
        path = self.archives(full, output)[0]
        root = full["parts"][0]["bag_id"]
        with tarfile.open(path) as tar:
            members = tar.getmembers()
            content = {m.name: tar.extractfile(m).read() for m in members if m.isfile()}
        original_path = root + "/" + full["snapshot"]["files"][0]["path"]
        content[original_path] = b"b"
        manifest_path = root + "/manifest-sha256.txt"
        content[manifest_path] = content[manifest_path].replace(hashlib.sha256(b"a").hexdigest().encode(), hashlib.sha256(b"b").hexdigest().encode())
        tags = root + "/tagmanifest-sha256.txt"
        content[tags] = b"".join(hashlib.sha256(content[root + "/" + name]).hexdigest().encode() + b"  " + name.encode() + b"\n" for name in ("bagit.txt", "bag-info.txt", "manifest-sha256.txt"))
        tampered = self.root / "consistent-bagit-wrong-inventory.tar"
        with tarfile.open(tampered, "w", format=tarfile.PAX_FORMAT) as tar:
            for member in members:
                data = content.get(member.name)
                if data is not None:
                    member.size = len(data)
                tar.addfile(member, io.BytesIO(data) if data is not None else None)
        self.assert_independently_valid(tampered)
        with self.assertRaisesRegex(ValueError, "inventory checksum"):
            preservation.verify_archive(tampered)


if __name__ == "__main__":
    unittest.main()
