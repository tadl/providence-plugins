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
        profile = json.loads((Path(__file__).parents[1] / "profiles/mdpn.json").read_text())
        self.snapshot["schema"]["bagit_profile"] = profile
        return profile

    def mdpn_metadata(self):
        return {"Source-Organization": "Traverse Area District Library", "Contact-Name": "Example Staff",
                "Contact-Email": "preservation@example.org"}

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
        original = self.original("1", b"a")
        original["original_filename"] = {"$base64": "/2EudGlm"}
        self.snapshot["files"] = [original]
        result, output = self.build()
        self.assertEqual(preservation.verify_archive(self.archives(result, output)[0])["snapshot"]["files"][0]["original_filename"], original["original_filename"])

    def test_unchanged_supplement_and_insufficient_space_are_refused(self):
        full, _ = self.build()
        with self.assertRaisesRegex(ValueError, "no changes since the baseline"):
            self.build(mode="supplemental", parent=full)
        class FullDisk:
            free = 0
        with patch.object(preservation.shutil, "disk_usage", return_value=FullDisk()):
            with self.assertRaisesRegex(ValueError, "Insufficient free staging space"):
                self.build()

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
