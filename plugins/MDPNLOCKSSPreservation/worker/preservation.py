#!/usr/bin/env python3
"""BagIt 1.0 packaging and offline reconstruction; Python standard library only.

SPDX-License-Identifier: GPL-3.0-or-later
"""

import argparse
import base64
import copy
import csv
from collections import Counter
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import sys
import tarfile


FORMAT = "mdpn-ca-preservation-1"
SOFTWARE = "MDPNLOCKSSPreservation/0.1.0"


def encoded(value):
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2) + "\n").encode("utf-8")


def digest(path):
    sha = hashlib.sha256()
    with open(path, "rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            sha.update(block)
    return sha.hexdigest()


def logical_path(spec):
    original = spec["original_filename"]
    if isinstance(original, dict):
        original = "filename_bytes_" + base64.b64decode(original["$base64"]).hex()
    name = re.sub(r"[^A-Za-z0-9_.-]", "_", original)[:100].strip(".") or "original"
    return "data/originals/" + hashlib.sha256(spec["key"].encode()).hexdigest()[:32] + "/" + name


def read_original(spec, destination=None):
    """Copy through an open regular-file descriptor; reject changes during capture."""
    sha = hashlib.sha256()
    md5 = hashlib.md5(usedforsecurity=False)
    source_path = spec["source_path"]
    if isinstance(source_path, dict):
        source_path = os.fsdecode(base64.b64decode(source_path["$base64"]))
    source = Path(source_path)
    if not source.is_absolute() or source.is_symlink():
        raise ValueError("An original must resolve to an absolute regular file")
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
    with os.fdopen(os.open(source, flags), "rb") as stream:
        before = os.fstat(stream.fileno())
        if not stat.S_ISREG(before.st_mode):
            raise ValueError("An original is not a regular file")
        out = None
        try:
            if destination:
                destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                out = destination.open("xb")
            count = 0
            for block in iter(lambda: stream.read(1024 * 1024), b""):
                sha.update(block)
                md5.update(block)
                count += len(block)
                if out:
                    out.write(block)
            after = os.fstat(stream.fileno())
        finally:
            if out:
                out.close()
    current = source.stat()
    identity = lambda s: (s.st_dev, s.st_ino, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
    if identity(before) != identity(after) or identity(after) != identity(current) or count != before.st_size:
        raise ValueError("An original changed during capture; run the deposit again")
    if spec.get("expected_md5") and md5.hexdigest().lower() != spec["expected_md5"].lower():
        raise ValueError("An original does not match Providence's recorded checksum")
    return {
        "key": spec["key"], "sha256": sha.hexdigest(), "size": count,
        "original_filename": spec["original_filename"], "owner": spec["owner"], "path": logical_path(spec),
    }


def fingerprint(snapshot):
    content = {k: snapshot[k] for k in ("repository_id", "collection_id", "records", "relationships", "schema")}
    content["files"] = [{k: f[k] for k in ("key", "sha256", "size", "original_filename", "owner")} for f in snapshot["files"]]
    content["external_references"] = snapshot.get("external_references", [])
    return hashlib.sha256(encoded(content)).hexdigest()


def changes(current, previous=None):
    previous = previous or {"records": {}, "relationships": {}, "files": [], "schema": {}}
    result = []
    for category in ("records", "relationships"):
        old = {t + ":" + str(k): v for t, rows in previous[category].items() for k, v in rows.items()}
        new = {t + ":" + str(k): v for t, rows in current[category].items() for k, v in rows.items()}
        for key in sorted(old.keys() | new.keys()):
            operation = "added" if key not in old else "removed" if key not in new else "changed" if old[key] != new[key] else None
            if operation:
                result.append({"category": category, "key": key, "operation": operation})
    old = {f["key"]: f for f in previous["files"]}
    new = {f["key"]: f for f in current["files"]}
    for key in sorted(old.keys() | new.keys()):
        operation = "added" if key not in old else "removed" if key not in new else "changed" if any(old[key][k] != new[key][k] for k in ("sha256", "original_filename", "owner")) else None
        if operation:
            result.append({"category": "files", "key": key, "operation": operation})
    if current["schema"] != previous.get("schema", {}):
        result.append({"category": "schema", "key": "metadata-schema", "operation": "changed"})
    return result


def compare_report(request):
    """Fresh fixity comparison, with no bags, staging files or ledger writes."""
    current = copy.deepcopy(request["snapshot"])
    for category in ("records", "relationships"):
        current[category] = current[category] or {}
    current["files"] = [read_original(f) for f in sorted(current["files"], key=lambda f: f["key"])]
    current_fingerprint = fingerprint(current)
    comparisons = {}
    for job_id, path in request["baselines"].items():
        saved = json.loads(Path(path).read_text(encoding="utf-8"))["snapshot"]
        if saved.get("format") != FORMAT or saved.get("deposit_id") != job_id or any(saved[k] != current[k] for k in ("repository_id", "collection_id")) or saved.get("fingerprint") != fingerprint(saved):
            raise ValueError("A preservation baseline is inconsistent or corrupt")
        counts = Counter(change["category"] + "_" + change["operation"] for change in changes(current, saved))
        if current.get("external_references", []) != saved.get("external_references", []):
            counts["external_references_changed"] += 1
        comparisons[job_id] = {"current": current_fingerprint == saved["fingerprint"], "changes": dict(counts)}
    return {"checked_at": current["captured_at"], "comparisons": comparisons}


def csv_bytes(rows, fields):
    stream = io.StringIO(newline="")
    writer = csv.DictWriter(stream, fieldnames=fields, lineterminator="\n")
    writer.writeheader()
    for row in rows:
        # JSON cells preserve types, container structure, locales and repeated values.
        writer.writerow({k: json.dumps(row.get(k), ensure_ascii=False, sort_keys=True) for k in fields})
    return stream.getvalue().encode("utf-8")


def metadata_files(snapshot):
    files = {
        "snapshot.json": encoded(snapshot), "metadata-schema.json": encoded(snapshot["schema"]),
        "files.csv": csv_bytes(snapshot["files"], ["key", "owner", "original_filename", "path", "size", "sha256", "bag_id"]),
        "changes.csv": csv_bytes(snapshot["changes"], ["category", "key", "operation"]),
        "relationships.csv": csv_bytes([
            {"table": t, "id": k, "record": r} for t, rows in snapshot["relationships"].items() for k, r in rows.items()
        ], ["table", "id", "record"]),
    }
    for name, table in (("collections", "ca_collections"), ("objects", "ca_objects"), ("representations", "ca_object_representations")):
        rows = [{"id": k, **v} for k, v in snapshot["records"].get(table, {}).items()]
        files[name + ".csv"] = csv_bytes(rows, ["id", "row", "labels", "attributes", "acl"])
    xml = snapshot["schema"].get("installation_profile_xml")
    if xml:
        files["installation-profile.xml"] = xml.encode("utf-8")
    return {"data/metadata/" + name: content for name, content in files.items()}


README = b"""MDPNLOCKSSPreservation recovery package, format version 1.

snapshot.json is the authoritative complete current catalogue snapshot. CSV cells
are JSON values, including strings; decode them rather than opening as formulas.
Rows retain source database IDs. Raw binary values use {\"$base64\": \"...\"}.
Do not run raw rows as SQL against another installation. Import through CA APIs,
mapping old IDs, vocabulary IDs, relationship types, locales and attribute IDs.
Original media references must be rebound to the files listed in files.csv.

All parts of a deposit repeat the same metadata snapshot. Bag-Count numbers the
parts of THIS DEPOSIT. Bag-Group-Identifier remains the collection's identifier
across full and supplemental deposits. Deposit IDs and parent IDs establish the
recovery chain. Supplements include only new or changed original file bytes;
files.csv gives the precise bag and path for every current file. Removed records
and relationships are documented in changes.csv. They do not delete older bags.
A new full deposit starts an independent chain.

Use the plugin's standalone preservation.py recover command with the target ID and all bags
in snapshot.json's chain. It validates every checksum before reconstruction and
produces catalogue-snapshot.json plus original files. Keep a trusted copy of the
plugin/recovery documentation separately. A tested CA importer and complete
system backups are still required to restore a running service. Users/passwords,
server configuration and regenerable derivatives are not application backups.
"""


def validate_profile(tags, profile):
    if profile.get("bagit_version", "1.0") != "1.0" or profile.get("manifest_algorithm", "sha256") != "sha256" or profile.get("serialization", "application/tar") != "application/tar":
        raise ValueError("Unsupported packaging profile")
    for tag in profile.get("required_tags", ["Source-Organization", "Contact-Name", "Contact-Email", "External-Identifier", "Bagging-Date", "Payload-Oxum"]):
        if not tags.get(tag):
            raise ValueError("A required BagIt profile tag is missing")
    if profile.get("source_organizations") and tags["Source-Organization"] not in profile["source_organizations"]:
        raise ValueError("Source organization is not allowed by the BagIt profile")


def build(request):
    snapshot = copy.deepcopy(request["snapshot"])
    # PHP encodes empty arrays as []; these two fields are keyed maps.
    for key in ("records", "relationships"):
        snapshot[key] = snapshot[key] or {}
    job = request["job"]
    parent = request.get("parent")
    if not re.fullmatch(r"[a-f0-9]{32}", job["id"]) or not re.fullmatch(r"[A-Z0-9]{2,12}", request["organization_code"]) or not isinstance(snapshot["collection_id"], int) or snapshot["collection_id"] < 1 or job["mode"] not in ("scan", "full", "supplemental"):
        raise ValueError("Invalid preservation job identity")
    if job["mode"] == "supplemental" and not parent:
        raise ValueError("A supplement requires a successful baseline")
    if parent and any(snapshot[k] != parent["snapshot"][k] for k in ("repository_id", "collection_id")):
        raise ValueError("The baseline belongs to another collection or installation")
    if job["mode"] == "supplemental" and job.get("parent_id") != parent["snapshot"]["deposit_id"]:
        raise ValueError("The supplemental parent does not match the supplied baseline")
    output = Path(request["output_directory"])
    stage = output / "staging"
    scan = job["mode"] == "scan"
    if not scan:
        estimated = sum(int(f.get("source_size", 0)) for f in snapshot["files"])
        if shutil.disk_usage(output).free < estimated * 2 + len(encoded(snapshot)) * 20 + 16 * 1024 * 1024:
            raise ValueError("Insufficient free staging space for a verified deposit")
        stage.mkdir(mode=0o700)
    old_files = {f["key"]: f for f in parent["snapshot"]["files"]} if parent else {}
    specs = sorted(snapshot.pop("files"), key=lambda f: f["key"])
    if len({f["key"] for f in specs}) != len(specs):
        raise ValueError("Duplicate original file identities")
    inventory, selected = [], []
    for spec in specs:
        path = stage / logical_path(spec) if not scan else None
        item = read_original(spec, path)
        old = old_files.get(item["key"])
        if job["mode"] == "supplemental" and old and all(item[k] == old[k] for k in ("sha256", "size", "path")):
            item["bag_id"] = old["bag_id"]
            path.unlink()
        elif not scan:
            selected.append(item)
        inventory.append(item)
    snapshot["files"] = inventory
    snapshot["compared_to_deposit_id"] = parent["snapshot"].get("deposit_id") if parent else None
    snapshot["fingerprint"] = fingerprint(snapshot)
    snapshot["changes"] = changes(snapshot, parent["snapshot"] if parent else None)
    if scan:
        return {"snapshot": snapshot, "parts": [], "changed": not parent or snapshot["fingerprint"] != parent["snapshot"]["fingerprint"]}
    if job["mode"] == "supplemental" and snapshot["fingerprint"] == parent["snapshot"]["fingerprint"]:
        raise ValueError("There are no changes since the baseline; choose a new full deposit if a fresh copy is required")
    snapshot.update({"format": FORMAT, "deposit_id": job["id"], "mode": job["mode"], "revision": job["revision"],
                     "parent_deposit_id": job.get("parent_id"), "bag_group_identifier": request["bag_group_identifier"]})
    # Reserve conservative space for repeated metadata and tar headers. The final
    # physical size is checked again; files are never split into byte fragments.
    limit = int(request["part_size_bytes"])
    reserve = len(encoded(snapshot)) * 6 + 128 * 1024 + len(inventory) * 2048
    if limit <= reserve:
        raise ValueError("The configured part size cannot hold the collection metadata")
    groups, group, size = [], [], reserve
    for item in selected:
        cost = item["size"] + 2048
        if cost + reserve > limit:
            raise ValueError("One original exceeds the configured part size; agree a larger limit with the repository")
        if group and size + cost > limit:
            groups.append(group)
            group, size = [], reserve
        group.append(item)
        size += cost
    groups.append(group)
    names = [request["organization_code"] + "_C" + str(snapshot["collection_id"]) + "_" + job["id"] + "_P" + str(i + 1).zfill(3) for i in range(len(groups))]
    for name, items in zip(names, groups):
        for item in items:
            item["bag_id"] = name
    chain = copy.deepcopy(parent["snapshot"]["chain"]) if job["mode"] == "supplemental" else []
    if chain:
        chain[-1]["parts"] = [{k: p[k] for k in ("bag_id", "filename", "sha256")} for p in parent["parts"]]
    chain.append({"deposit_id": job["id"], "mode": job["mode"], "parts": [{"bag_id": n, "filename": n + ".tar"} for n in names]})
    snapshot["chain"] = chain
    metadata = metadata_files(snapshot)
    parts = []
    for number, (name, items) in enumerate(zip(names, groups), 1):
        bag = output / name
        bag.mkdir(mode=0o700)
        payloads = dict(metadata)
        payloads["data/README.txt"] = README
        for relative, content in payloads.items():
            path = bag / relative
            path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            path.write_bytes(content)
        for item in items:
            destination = bag / item["path"]
            destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            (stage / item["path"]).rename(destination)
        payload_paths = sorted(p for p in bag.rglob("*") if p.is_file())
        manifest = "".join(digest(p) + "  " + p.relative_to(bag).as_posix() + "\n" for p in payload_paths)
        (bag / "manifest-sha256.txt").write_text(manifest, encoding="utf-8")
        (bag / "bagit.txt").write_text("BagIt-Version: 1.0\nTag-File-Character-Encoding: UTF-8\n", encoding="utf-8")
        tags = dict(request["bag_metadata"])
        tags.update({"Bag-Group-Identifier": snapshot["bag_group_identifier"], "External-Identifier": name,
                     "Internal-Sender-Identifier": snapshot["repository_id"] + ":ca_collections:" + str(snapshot["collection_id"]),
                     "Bag-Count": str(number) + " of " + str(len(names)), "Bagging-Date": snapshot["captured_at"][:10],
                     "Bagging-Software": SOFTWARE, "Payload-Oxum": str(sum(p.stat().st_size for p in payload_paths)) + "." + str(len(payload_paths)),
                     "Preservation-Deposit-Identifier": job["id"], "Preservation-Mode": job["mode"],
                     "Preservation-Revision": str(job["revision"])})
        if job.get("parent_id"):
            tags["Preservation-Parent-Deposit"] = job["parent_id"]
        validate_profile(tags, snapshot["schema"].get("bagit_profile", {}))
        for tag, value in tags.items():
            if re.search(r"[\r\n\x00-\x1f\x7f]", tag + str(value)) or ":" in tag:
                raise ValueError("Unsafe BagIt tag")
        (bag / "bag-info.txt").write_text("".join(k + ": " + str(v) + "\n" for k, v in tags.items()), encoding="utf-8")
        (bag / "tagmanifest-sha256.txt").write_text("".join(digest(bag / f) + "  " + f + "\n" for f in ("bagit.txt", "bag-info.txt", "manifest-sha256.txt")), encoding="utf-8")
        archive = output / (name + ".tar")
        with tarfile.open(archive, "x", format=tarfile.PAX_FORMAT) as tar:
            tar.add(bag, arcname=name, recursive=True)
        # Verify the serialized archive, not merely the pre-serialization folder.
        verify_archive(archive)
        if archive.stat().st_size > limit:
            raise ValueError("Serialized bag exceeds the configured part size; reduce the target or increase its limit")
        parts.append({"bag_id": name, "filename": archive.name, "size": archive.stat().st_size, "sha256": digest(archive)})
        shutil.rmtree(bag)
    shutil.rmtree(stage)
    return {"snapshot": snapshot, "parts": parts, "changed": True}


def manifest_entries(content):
    result = {}
    for line in content.decode("utf-8").splitlines():
        match = re.fullmatch(r"([a-f0-9]{64})  (.+)", line)
        if not match or match[2] in result:
            raise ValueError("Invalid or duplicate checksum manifest entry")
        result[match[2]] = match[1]
    return result


def verify_archive(path):
    """Reject unsafe/duplicate members and verify complete payload/tag coverage."""
    with tarfile.open(path, "r:*") as tar:
        members = {}
        root = None
        for member in tar:
            p = PurePosixPath(member.name)
            if p.is_absolute() or ".." in p.parts or "\\" in member.name or not p.parts or member.issym() or member.islnk():
                raise ValueError("Unsafe tar member")
            if member.name.rstrip("/") != p.as_posix():
                raise ValueError("Non-canonical tar member")
            if not member.isfile() and not member.isdir():
                raise ValueError("Unsupported tar member")
            if root is None:
                root = p.parts[0]
            if p.parts[0] != root or member.name in members:
                raise ValueError("Multiple bag roots or duplicate tar members")
            members[member.name] = member
        def read(relative):
            member = members.get(root + "/" + relative)
            if not member or not member.isfile() or member.size > 512 * 1024 * 1024:
                raise ValueError("Missing or oversized bag metadata")
            return tar.extractfile(member).read()
        if read("bagit.txt") != b"BagIt-Version: 1.0\nTag-File-Character-Encoding: UTF-8\n":
            raise ValueError("Unsupported BagIt declaration")
        payload = manifest_entries(read("manifest-sha256.txt"))
        tags = manifest_entries(read("tagmanifest-sha256.txt"))
        expected = {name[len(root) + 1:] for name, member in members.items() if member.isfile()}
        if set(payload) != {p for p in expected if p.startswith("data/")}:
            raise ValueError("Payload manifest does not cover exactly the payload")
        if set(tags) != expected - set(payload) - {"tagmanifest-sha256.txt"}:
            raise ValueError("Tag manifest does not cover exactly the tag files")
        for relative, expected_digest in {**payload, **tags}.items():
            if relative not in expected:
                raise ValueError("Manifest references a missing file")
            sha = hashlib.sha256()
            with tar.extractfile(members[root + "/" + relative]) as stream:
                for block in iter(lambda: stream.read(1024 * 1024), b""):
                    sha.update(block)
            if sha.hexdigest() != expected_digest:
                raise ValueError("Bag checksum validation failed")
        snapshot = json.loads(read("data/metadata/snapshot.json"))
        if snapshot.get("format") != FORMAT or snapshot.get("fingerprint") != fingerprint(snapshot):
            raise ValueError("Invalid preservation snapshot")
        bag_info = dict(line.split(": ", 1) for line in read("bag-info.txt").decode("utf-8").splitlines())
        validate_profile(bag_info, snapshot["schema"].get("bagit_profile", {}))
        if bag_info.get("External-Identifier") != root or bag_info.get("Preservation-Deposit-Identifier") != snapshot["deposit_id"]:
            raise ValueError("Bag and snapshot identifiers disagree")
        inventory = snapshot["files"]
        if len({f["key"] for f in inventory}) != len(inventory) or len({f["path"] for f in inventory}) != len(inventory):
            raise ValueError("Duplicate file inventory identities or paths")
        local_files = [f for f in inventory if f["bag_id"] == root]
        if {f["path"] for f in local_files} != {p for p in payload if p.startswith("data/originals/")}:
            raise ValueError("Original payload and inventory disagree")
        for file in local_files:
            if payload[file["path"]] != file["sha256"] or members[root + "/" + file["path"]].size != file["size"]:
                raise ValueError("Original inventory checksum or size is inconsistent")
        deposit = snapshot["chain"][-1]
        part_ids = [p["bag_id"] for p in deposit["parts"]]
        if deposit["deposit_id"] != snapshot["deposit_id"] or root not in part_ids or bag_info.get("Bag-Count") != str(part_ids.index(root) + 1) + " of " + str(len(part_ids)):
            raise ValueError("Bag part numbering and deposit metadata disagree")
        return {"bag_id": root, "snapshot": snapshot, "sha256": digest(path), "path": str(path)}


def recover(paths, deposit_id, output):
    archives = {}
    for path in paths:
        bag = verify_archive(path)
        if bag["bag_id"] in archives:
            raise ValueError("Duplicate bag identifier")
        archives[bag["bag_id"]] = bag
    candidates = [b["snapshot"] for b in archives.values() if b["snapshot"]["deposit_id"] == deposit_id]
    if not candidates or any(s != candidates[0] for s in candidates):
        raise ValueError("Target deposit metadata is missing or inconsistent")
    snapshot = candidates[0]
    chain = snapshot["chain"]
    if not chain or chain[0]["mode"] != "full" or chain[-1]["deposit_id"] != deposit_id:
        raise ValueError("Invalid recovery chain")
    required = set()
    parent = None
    for entry in chain:
        part_snapshot = None
        for part in entry["parts"]:
            required.add(part["bag_id"])
            bag = archives.get(part["bag_id"])
            if not bag or (part.get("sha256") and bag["sha256"] != part["sha256"]):
                raise ValueError("A required recovery bag is missing or has the wrong archive checksum")
            s = bag["snapshot"]
            if part_snapshot is not None and s != part_snapshot:
                raise ValueError("Parts of a deposit have inconsistent metadata")
            part_snapshot = s
            if any(s[k] != snapshot[k] for k in ("repository_id", "collection_id", "bag_group_identifier")) or s["deposit_id"] != entry["deposit_id"] or s["parent_deposit_id"] != parent:
                raise ValueError("Inconsistent recovery lineage")
        parent = entry["deposit_id"]
    output = Path(output)
    output.mkdir(mode=0o700, parents=False, exist_ok=False)
    try:
        for file in snapshot["files"]:
            if file["bag_id"] not in required:
                raise ValueError("File references a bag outside the recovery chain")
            relative = PurePosixPath(file["path"])
            if relative.is_absolute() or ".." in relative.parts or not str(relative).startswith("data/originals/") or "\\" in str(relative):
                raise ValueError("Unsafe original path")
            destination = output / relative
            destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            bag = archives[file["bag_id"]]
            with tarfile.open(bag["path"], "r:*") as tar:
                with tar.extractfile(file["bag_id"] + "/" + file["path"]) as source, destination.open("xb") as target:
                    shutil.copyfileobj(source, target, length=1024 * 1024)
            if destination.stat().st_size != file["size"] or digest(destination) != file["sha256"]:
                raise ValueError("Reconstructed original has an invalid size or checksum")
        (output / "catalogue-snapshot.json").write_bytes(encoded(snapshot))
        (output / "README.txt").write_bytes(README)
    except Exception:
        # Leave the partial recovery for diagnosis; never present it as complete.
        (output / "RECOVERY_INCOMPLETE.txt").write_text("Recovery failed. Discard or inspect this directory before retrying.\n", encoding="utf-8")
        raise
    return {"deposit_id": deposit_id, "files": len(snapshot["files"]), "output_directory": str(output)}


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    package = sub.add_parser("build")
    package.add_argument("request")
    sub.add_parser("compare", help="Read a maintenance comparison request from stdin; write summaries only")
    verify = sub.add_parser("verify")
    verify.add_argument("archives", nargs="+")
    restore = sub.add_parser("recover")
    restore.add_argument("--deposit", required=True)
    restore.add_argument("--output", required=True)
    restore.add_argument("archives", nargs="+")
    args = parser.parse_args()
    try:
        if args.command == "build":
            result = build(json.loads(Path(args.request).read_text(encoding="utf-8")))
        elif args.command == "compare":
            result = compare_report(json.load(sys.stdin.buffer))
        elif args.command == "verify":
            result = [{k: v for k, v in verify_archive(p).items() if k != "snapshot"} for p in args.archives]
        else:
            result = recover(args.archives, args.deposit, args.output)
        sys.stdout.buffer.write(encoded(result))
        return 0
    except (OSError, ValueError, KeyError, TypeError, tarfile.TarError) as error:
        # Source paths/catalogue values are private; detailed tracebacks stay out
        # of shared process logs. The calling service records a bounded message.
        message = str(error) if type(error) is ValueError else type(error).__name__
        print("Preservation worker failed: " + message, file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
