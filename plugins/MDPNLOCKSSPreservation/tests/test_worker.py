"""Synthetic deposit/recovery tests. No CA bootstrap, network or real records."""
import copy
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

    def build(self, snapshot=None, mode="full", parent=None, limit=5_000_000):
        self.counter += 1
        output = self.root / ("job" + str(self.counter))
        output.mkdir()
        job = {"id": str(self.counter).zfill(32), "revision": self.counter, "mode": mode,
               "parent_id": parent["snapshot"].get("deposit_id") if mode == "supplemental" and parent else None}
        request = {"snapshot": snapshot or self.snapshot, "job": job, "parent": parent, "output_directory": str(output),
                   "bag_group_identifier": "EXAMPLE_synthetic_C12", "organization_code": "EXAMPLE", "part_size_bytes": limit,
                   "bag_metadata": {"Contact-Name": "Example Staff", "Contact-Email": "preservation@example.org", "Source-Organization": "Example Archive"}}
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
        self.assertEqual(len({f["path"] for f in result["snapshot"]["files"]}), 2)
        snapshot = preservation.verify_archive(paths[0])["snapshot"]
        self.assertEqual(snapshot["records"], self.snapshot["records"])
        self.assertNotIn("source_path", json.dumps(snapshot))
        self.assertFalse((output / "staging").exists())

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
