#!/usr/bin/env python3
"""BagIt 1.0 packaging and offline reconstruction; Python standard library only.

SPDX-License-Identifier: GPL-3.0-or-later
"""

import argparse
import base64
import copy
import csv
from collections import Counter
from datetime import datetime, timezone
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
import tempfile


FORMAT = "mdpn-ca-preservation-1"
SOFTWARE = "MDPNLOCKSSPreservation/0.1.12"
TAG_READ_LIMIT = 512 * 1024 * 1024


def encoded(value):
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2) + "\n").encode("utf-8")


def encoded_digest(value):
    # Match encoded() without allocating another complete recovery snapshot.
    sha = hashlib.sha256()
    for chunk in json.JSONEncoder(ensure_ascii=False, sort_keys=True, indent=2).iterencode(value):
        sha.update(chunk.encode("utf-8"))
    sha.update(b"\n")
    return sha.hexdigest()


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


def original_path(spec):
    source_path = spec["source_path"]
    if isinstance(source_path, dict):
        source_path = os.fsdecode(base64.b64decode(source_path["$base64"]))
    source = Path(source_path)
    if not source.is_absolute() or source.is_symlink():
        raise ValueError("An original must resolve to an absolute regular file")
    return source


def file_identity(info):
    return info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns


def read_original(spec, destination=None):
    """Read through an open regular-file descriptor; reject changes during capture."""
    sha = hashlib.sha256()
    md5 = hashlib.md5(usedforsecurity=False)
    source = original_path(spec)
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
    if file_identity(before) != file_identity(after) or file_identity(after) != file_identity(current) or count != before.st_size:
        raise ValueError("An original changed during capture; run the deposit again")
    if spec.get("expected_md5") and md5.hexdigest().lower() != spec["expected_md5"].lower():
        raise ValueError("An original does not match Providence's recorded checksum")
    return {
        "key": spec["key"], "sha256": sha.hexdigest(), "size": count,
        "original_filename": spec["original_filename"], "owner": spec["owner"], "path": logical_path(spec),
    }


def tar_member(name, size=0, directory=False):
    member = tarfile.TarInfo(name)
    member.size = size
    member.type = tarfile.DIRTYPE if directory else tarfile.REGTYPE
    member.mode = 0o700 if directory else 0o600
    member.uid, member.gid = os.getuid(), os.getgid()
    return member


def add_original_to_tar(tar, root, spec, item):
    """Stream originals into the archive; its manifests verify the captured hash."""
    source = original_path(spec)
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
    with os.fdopen(os.open(source, flags), "rb") as stream:
        before = os.fstat(stream.fileno())
        if not stat.S_ISREG(before.st_mode) or before.st_size != item["size"]:
            raise ValueError("An original changed before archive creation")
        tar.addfile(tar_member(root + "/" + item["path"], item["size"]), stream)
        after = os.fstat(stream.fileno())
    if file_identity(before) != file_identity(after) or file_identity(after) != file_identity(source.stat()):
        raise ValueError("An original changed during archive creation")


def fingerprint(snapshot):
    content = {k: snapshot[k] for k in ("repository_id", "collection_id", "records", "relationships", "schema")}
    content["files"] = [{k: f[k] for k in ("key", "sha256", "size", "original_filename", "owner")} for f in snapshot["files"]]
    content["external_references"] = snapshot.get("external_references", [])
    return encoded_digest(content)


PRIMARY_TABLES = {"ca_collections", "ca_objects", "ca_object_representations", "ca_attribute_value_multifiles"}
MEANING_FIELDS = {
    "ca_locales": ("locale_id", "name", "language", "country", "dialect"),
    "ca_metadata_elements": ("element_id", "element_code", "datatype", "list_id", "parent_id", "deleted"),
    "ca_metadata_element_labels": ("element_id", "locale_id", "name", "description", "is_preferred"),
    "ca_lists": ("list_id", "list_code", "is_hierarchical", "deleted"),
    "ca_list_labels": ("list_id", "locale_id", "name"),
    "ca_list_items": ("item_id", "list_id", "parent_id", "idno", "item_value", "deleted"),
    "ca_list_item_labels": ("item_id", "locale_id", "name_singular", "name_plural", "description", "is_preferred", "sdatetime", "edatetime"),
    "ca_relationship_types": ("type_id", "table_num", "type_code", "parent_id", "sub_type_left_id", "sub_type_right_id"),
    "ca_relationship_type_labels": ("type_id", "locale_id", "typename", "typename_reverse", "description", "description_reverse"),
}
DEFINITION_LABELS = {
    "ca_metadata_elements": ("ca_metadata_element_labels", "element_id"),
    "ca_lists": ("ca_list_labels", "list_id"),
    "ca_list_items": ("ca_list_item_labels", "item_id"),
    "ca_relationship_types": ("ca_relationship_type_labels", "type_id"),
}


def collection_records(snapshot):
    # Authority descriptions remain recovery context, rather than making every
    # collection linked to that person stale when their subject headings change.
    result = {}
    for table, rows in snapshot["records"].items():
        if table not in PRIMARY_TABLES and not table.startswith("ca_object_representation_"):
            continue
        result[table] = {key: {**record, "row": {k: v for k, v in record.get("row", {}).items()
                                              if k not in ("hier_left", "hier_right", "view_count")
                                              and not (k == "rank" and table == "ca_collections"
                                                       and str(key) == str(snapshot.get("collection_id")))}}
                         for key, record in rows.items() if not record.get("generated_preview")}
        if not result[table]:
            del result[table]
    return result


def catalogue_schema(snapshot):
    """Only definitions needed to interpret the collection's actual values."""
    definitions = snapshot["schema"].get("definitions") or {}
    tables = snapshot["schema"].get("tables") or {}
    pending, seen, result, labels = [], set(), {}, {}
    for owner, (table, field) in DEFINITION_LABELS.items():
        for key, row in (definitions.get(table) or {}).items():
            labels.setdefault((owner, str(row[field])), []).append((table, str(key)))

    def add(table, key):
        if table in MEANING_FIELDS and key not in (None, "", 0, "0"):
            pending.append((table, str(key)))

    def references(table, row):
        for relation in (tables.get(table, {}).get("foreign_keys") or {}).values():
            add(relation["one_table"], row.get(relation["many_table_field"]))
        for field, target in (("element_id", "ca_metadata_elements"), ("item_id", "ca_list_items"),
                              ("list_id", "ca_lists"), ("locale_id", "ca_locales")):
            add(target, row.get(field))
        if table in MEANING_FIELDS:
            add(table, row.get("parent_id"))

    for category in (collection_records(snapshot), snapshot["relationships"]):
        for table, rows in category.items():
            for record in rows.values():
                references(table, record.get("row", {}))
                for label in record.get("labels", []):
                    references(tables.get(table, {}).get("label_table"), label)
                for attribute in record.get("attributes", []):
                    references("ca_attributes", attribute.get("row", {}))
                    for value in attribute.get("values", []):
                        references("ca_attribute_values", value)
    while pending:
        table, key = pending.pop()
        if (table, key) in seen:
            continue
        seen.add((table, key))
        row = (definitions.get(table) or {}).get(key)
        semantic = {k: row[k] for k in MEANING_FIELDS[table] if k in row} if isinstance(row, dict) else row
        result.setdefault(table, {})[key] = semantic
        if isinstance(semantic, dict):
            references(table, semantic)
            pending.extend(labels.get((table, key), []))
    return result


def content_fingerprint(snapshot):
    # The complete schema and authority context stay protected by the original
    # integrity fingerprint. Freshness follows collection content and its meaning.
    return fingerprint({**snapshot, "records": collection_records(snapshot), "schema": catalogue_schema(snapshot), "external_references": []})


def changes(current, previous=None):
    previous = previous or {"records": {}, "relationships": {}, "files": [], "schema": {}}
    result = []
    for category in ("records", "relationships"):
        old_rows = collection_records(previous) if category == "records" else previous[category]
        new_rows = collection_records(current) if category == "records" else current[category]
        old = {t + ":" + str(k): v for t, rows in old_rows.items() for k, v in rows.items()}
        new = {t + ":" + str(k): v for t, rows in new_rows.items() for k, v in rows.items()}
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
    if catalogue_schema(current) != catalogue_schema(previous):
        result.append({"category": "schema", "key": "metadata-schema", "operation": "changed"})
    return result


def compare_report(request):
    """Fresh fixity comparison, with no bags, staging files or ledger writes."""
    current = copy.deepcopy(request["snapshot"])
    for category in ("records", "relationships"):
        current[category] = current[category] or {}
    current["files"] = [read_original(f) for f in sorted(current["files"], key=lambda f: f["key"])]
    current_fingerprint = content_fingerprint(current)
    comparisons = {}
    for job_id, path in request["baselines"].items():
        saved = json.loads(Path(path).read_text(encoding="utf-8"))["snapshot"]
        if saved.get("format") != FORMAT or saved.get("deposit_id") != job_id or any(saved[k] != current[k] for k in ("repository_id", "collection_id")) or saved.get("fingerprint") != fingerprint(saved):
            raise ValueError("A preservation baseline is inconsistent or corrupt")
        counts = Counter(change["category"] + "_" + change["operation"] for change in changes(current, saved))
        matches = current_fingerprint == content_fingerprint(saved)
        comparisons[job_id] = {"current": matches, "recovery_context_changed": matches and fingerprint(current) != saved["fingerprint"], "changes": dict(counts)}
    return {"checked_at": current["captured_at"], "comparisons": comparisons}


def scan_row_hash(table, key, row, root):
    if table in MEANING_FIELDS:
        row = {field: row[field] for field in MEANING_FIELDS[table] if field in row}
    else:
        row = {field: value for field, value in row.items() if field not in ("hier_left", "hier_right", "view_count")
               and not (field == "rank" and table == "ca_collections" and str(key) == str(root))}
    return hashlib.sha256(json.dumps(row, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")).hexdigest()


def scan_inventory(request):
    """One-time compact watch inventory; never open originals or validate hashes."""
    with Path(request["path"]).open(encoding="utf-8") as stream:
        snapshot = json.load(stream)["snapshot"]
    expected = request["expected"]
    if any(snapshot.get(key) != value for key, value in expected.items()):
        raise ValueError("The saved scan inventory does not match its deposit")
    scope = {table: [key for key, record in rows.items() if not record.get("generated_preview")]
                      for table, rows in snapshot["records"].items()
                      if table in PRIMARY_TABLES or table.startswith("ca_object_representation_")}
    scope.update({table: list(rows) for table, rows in snapshot["relationships"].items()})
    hashes = {}
    tables = snapshot["schema"].get("tables", {})

    def add(table, row, key=None):
        if table and isinstance(row, dict):
            key = key if key is not None else row.get(tables.get(table, {}).get("primary_key"))
            if key is not None:
                hashes.setdefault(table, {})[str(key)] = scan_row_hash(table, key, row, snapshot["collection_id"])

    for category in (collection_records(snapshot), snapshot["relationships"]):
        for table, records in category.items():
            for key, record in records.items():
                add(table, record["row"], key)
                for label in record.get("labels", []):
                    add(tables.get(table, {}).get("label_table"), label)
                for attribute in record.get("attributes", []):
                    add("ca_attributes", attribute.get("row", {}))
                    for value in attribute.get("values", []):
                        add("ca_attribute_values", value)
                for acl in record.get("acl", []):
                    add("ca_acl", acl)
    for table, rows in catalogue_schema(snapshot).items():
        for key, row in rows.items():
            add(table, row, key)
    return {"fingerprint": snapshot["fingerprint"], "change_log_cursor": snapshot.get("change_log_cursor"), "scope": scope, "row_hashes": hashes,
            "files": [{key: item[key] for key in ("key", "size", "original_filename", "owner")} for item in snapshot["files"]]}


def scan_row_changes(request):
    count = 0
    for event in request["events"]:
        table, key = event["table"], str(event["row_id"])
        row = (request["rows"].get(table) or {}).get(key)
        previous = (request["hashes"].get(table) or {}).get(key)
        # Unknown synthetic fields (such as ACL audit entries) cannot safely be
        # cleared just because the owner's intrinsic row has stayed unchanged.
        unknown = row is not None and event.get("fields") is not None and set(event["fields"]) - row.keys()
        if row is None or previous is None or unknown or previous != scan_row_hash(table, key, row, request["collection_id"]):
            count += 1
    return {"changed": count}


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
Generated page/frame records marked generated_preview with disposition regenerate
retain metadata but omit derivative bytes. Recreate those previews from their
preserved source_file_key using Providence's media processing.

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
    for name, allowed in profile.get("tag_values", {}).items():
        if tags.get(name) and tags[name] not in allowed:
            raise ValueError("A BagIt tag value is not allowed by the current profile: " + name)


def dart3_bag_info(tags, profile, payload_bytes):
    """Follow the MDPN profile's DART3 order, including empty optional tags."""
    order = profile.get("bag_info_order")
    if not order:
        return tags
    ordered = {name: tags.get(name, profile.get("bag_info_defaults", {}).get(name, "")) for name in order}
    # Keep DART's optional line, without requesting receiver-side AU grouping.
    ordered["Bag-Group-Identifier"] = ""
    # DART3's Bag-Size is the payload size, scaled by 1024 with SI labels.
    if payload_bytes < 1024:
        size = str(payload_bytes) + " B"
    else:
        divisor, exponent = 1024, 0
        while payload_bytes // divisor >= 1024 and exponent < 5:
            divisor *= 1024
            exponent += 1
        size = f"{payload_bytes / divisor:.1f} {'kMGTPE'[exponent]}B"
    ordered["Bag-Size"] = size
    ordered["Bagging-Date"] = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    # DART appends this automatically when it is absent from the imported tags.
    ordered["BagIt-Profile-Identifier"] = profile["bagit_profile_identifier"]
    return ordered


def build(request):
    output = Path(request["output_directory"])
    # No working copy of the originals. Only verified archives leave this private
    # workspace; any build failure removes every archive generated by that build.
    with tempfile.TemporaryDirectory(prefix=".build-", dir=output) as workspace:
        result = build_archives(request, Path(workspace))
        published = []
        try:
            for part in result["parts"]:
                destination = output / part["filename"]
                # Atomic, same-filesystem publication without overwriting a file.
                os.link(Path(workspace) / part["filename"], destination)
                published.append(destination)
        except BaseException:
            for destination in published:
                destination.unlink()
            raise
    return result


def build_archives(request, output):
    snapshot = copy.deepcopy(request["snapshot"])
    # PHP encodes empty arrays as []; these two fields are keyed maps.
    for key in ("records", "relationships"):
        snapshot[key] = snapshot[key] or {}
    job = request["job"]
    parent = request.get("parent")
    if not re.fullmatch(r"[a-f0-9]{32}", job["id"]) or not re.fullmatch(r"[A-Z0-9]{2,12}", request["organization_code"]) or not isinstance(snapshot["collection_id"], int) or snapshot["collection_id"] < 1 or job["mode"] not in ("scan", "full", "supplemental"):
        raise ValueError("Invalid preservation job identity")
    if type(job.get("revision")) is not int or job["revision"] < (0 if job["mode"] == "scan" else 1):
        raise ValueError("Invalid preservation revision")
    if job["mode"] == "supplemental" and not parent:
        raise ValueError("A supplement requires a successful baseline")
    if parent and any(snapshot[k] != parent["snapshot"][k] for k in ("repository_id", "collection_id")):
        raise ValueError("The baseline belongs to another collection or installation")
    if job["mode"] == "supplemental" and job.get("parent_id") != parent["snapshot"]["deposit_id"]:
        raise ValueError("The supplemental parent does not match the supplied baseline")
    scan = job["mode"] == "scan"
    old_files = {f["key"]: f for f in parent["snapshot"]["files"]} if parent else {}
    specs = sorted(snapshot.pop("files"), key=lambda f: f["key"])
    if len({f["key"] for f in specs}) != len(specs):
        raise ValueError("Duplicate original file identities")
    inventory, selected = [], []
    for spec in specs:
        item = read_original(spec)
        old = old_files.get(item["key"])
        if job["mode"] == "supplemental" and old and all(item[k] == old[k] for k in ("sha256", "size", "path")):
            item["bag_id"] = old["bag_id"]
        elif not scan:
            selected.append(item)
        inventory.append(item)
    snapshot["files"] = inventory
    snapshot["compared_to_deposit_id"] = parent["snapshot"].get("deposit_id") if parent else None
    snapshot["fingerprint"] = fingerprint(snapshot)
    snapshot["changes"] = changes(snapshot, parent["snapshot"] if parent else None)
    changed = not parent or content_fingerprint(snapshot) != content_fingerprint(parent["snapshot"])
    if scan:
        return {"snapshot": snapshot, "parts": [], "changed": changed}
    if job["mode"] == "supplemental" and not changed:
        return {"snapshot": snapshot, "parts": [], "bag_info": {}, "changed": False}
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
    prefix = request["organization_code"] + "_C" + str(snapshot["collection_id"]) + "_" + str(job["revision"]).zfill(3)
    names = [prefix if len(groups) == 1 else prefix + "_P" + str(i + 1).zfill(3) for i in range(len(groups))]
    for name, items in zip(names, groups):
        for item in items:
            item["bag_id"] = name
    chain = copy.deepcopy(parent["snapshot"]["chain"]) if job["mode"] == "supplemental" else []
    if chain:
        chain[-1]["parts"] = [{k: p[k] for k in ("bag_id", "filename", "sha256")} for p in parent["parts"]]
    chain.append({"deposit_id": job["id"], "mode": job["mode"], "parts": [{"bag_id": n, "filename": n + ".tar"} for n in names]})
    snapshot["chain"] = chain
    metadata = metadata_files(snapshot)
    metadata["data/README.txt"] = README
    # Reserve archive bytes and conservative headers, with no duplicate payload
    # tree. Unchanged originals do not consume space in a supplemental deposit.
    required = sum(item["size"] for item in selected) + len(groups) * (
        sum(len(content) for content in metadata.values()) + 128 * 1024
    ) + len(selected) * 4096 + 16 * 1024 * 1024
    if shutil.disk_usage(output).free < required:
        raise ValueError("Insufficient free space for preservation archives")
    sources = {spec["key"]: spec for spec in specs}
    parts, bag_info = [], {}
    for number, (name, items) in enumerate(zip(names, groups), 1):
        payload_hashes = {relative: hashlib.sha256(content).hexdigest() for relative, content in metadata.items()}
        payload_hashes.update({item["path"]: item["sha256"] for item in items})
        manifest = "".join(payload_hashes[relative] + "  " + relative + "\n" for relative in sorted(payload_hashes)).encode("utf-8")
        payload_bytes = sum(len(content) for content in metadata.values()) + sum(item["size"] for item in items)
        tags = dict(request["bag_metadata"])
        tags.update({"Bag-Group-Identifier": snapshot["bag_group_identifier"], "External-Identifier": name,
                     "Internal-Sender-Identifier": snapshot["repository_id"] + ":ca_collections:" + str(snapshot["collection_id"]),
                     "Bag-Count": str(number) + " of " + str(len(names)), "Bagging-Date": snapshot["captured_at"][:10],
                     "Bagging-Software": SOFTWARE, "Payload-Oxum": str(payload_bytes) + "." + str(len(payload_hashes))})
        profile = snapshot["schema"].get("bagit_profile", {})
        tags = dart3_bag_info(tags, profile, payload_bytes)
        validate_profile(tags, profile)
        for tag, value in tags.items():
            if re.search(r"[\r\n\x00-\x1f\x7f]", tag + str(value)) or ":" in tag:
                raise ValueError("Unsafe BagIt tag")
        if profile.get("bag_info_order"):
            # Match DART3 TagDefinition.ToFormattedString after input validation.
            tags = {name: re.sub(r"[\t\n\f\r ]+", " ", str(value)).strip() for name, value in tags.items()}
        bag_info[name] = "".join(k + ": " + str(v) + "\n" for k, v in tags.items())
        tag_files = {"bagit.txt": b"BagIt-Version: 1.0\nTag-File-Character-Encoding: UTF-8\n",
                     "bag-info.txt": bag_info[name].encode("utf-8"), "manifest-sha256.txt": manifest}
        tag_files["tagmanifest-sha256.txt"] = "".join(hashlib.sha256(tag_files[f]).hexdigest() + "  " + f + "\n"
                                                   for f in ("bagit.txt", "bag-info.txt", "manifest-sha256.txt")).encode("utf-8")
        archive = output / (name + ".tar")
        with tarfile.open(archive, "x", format=tarfile.PAX_FORMAT) as tar:
            tar.addfile(tar_member(name, directory=True))
            for relative, content in sorted({**metadata, **tag_files}.items()):
                tar.addfile(tar_member(name + "/" + relative, len(content)), io.BytesIO(content))
            for item in items:
                add_original_to_tar(tar, name, sources[item["key"]], item)
        # Verify the serialized archive, not merely the pre-serialization folder.
        verified = verify_archive(archive, expected_snapshot=snapshot)
        if archive.stat().st_size > limit:
            raise ValueError("Serialized bag exceeds the configured part size; reduce the target or increase its limit")
        parts.append({"bag_id": name, "filename": archive.name, "size": archive.stat().st_size, "sha256": verified["sha256"]})
    return {"snapshot": snapshot, "parts": parts, "bag_info": bag_info, "changed": True}


def manifest_entries(content):
    result = {}
    for line in content.decode("utf-8").splitlines():
        match = re.fullmatch(r"([a-f0-9]{64})  (.+)", line)
        if not match or match[2] in result:
            raise ValueError("Invalid or duplicate checksum manifest entry")
        result[match[2]] = match[1]
    return result


def verify_archive(path, expected_snapshot=None):
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
        def read(relative, limit=TAG_READ_LIMIT):
            member = members.get(root + "/" + relative)
            if not member or not member.isfile():
                raise ValueError("Missing bag metadata file: " + relative)
            if limit is not None and member.size > limit:
                raise ValueError("Bag metadata exceeds the tag-file read limit: " + relative)
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
        if expected_snapshot is not None:
            # Packaging already holds the snapshot. Prove it matches the verified
            # serialized payload instead of parsing a second potentially huge copy.
            if payload.get("data/metadata/snapshot.json") != encoded_digest(expected_snapshot):
                raise ValueError("Serialized recovery snapshot differs from the captured snapshot")
            snapshot = expected_snapshot
        else:
            # Recovery snapshots grow with collection size; the small tag-file
            # limit must not reject a legitimate, checksummed catalogue export.
            snapshot = json.loads(read("data/metadata/snapshot.json", limit=None))
        if snapshot.get("format") != FORMAT or snapshot.get("fingerprint") != fingerprint(snapshot):
            raise ValueError("Invalid preservation snapshot")
        bag_info = dict(line.split(": ", 1) for line in read("bag-info.txt").decode("utf-8").splitlines())
        validate_profile(bag_info, snapshot["schema"].get("bagit_profile", {}))
        if bag_info.get("External-Identifier") != root:
            raise ValueError("Bag and snapshot identifiers disagree")
        # New bags keep recovery provenance in the checksummed snapshot only.
        # Still check these redundant fields when reading an older archive.
        legacy = {"Preservation-Deposit-Identifier": snapshot["deposit_id"],
                  "Preservation-Mode": snapshot["mode"], "Preservation-Revision": str(snapshot["revision"]),
                  "Preservation-Parent-Deposit": snapshot.get("parent_deposit_id")}
        if any(name in bag_info and bag_info[name] != value for name, value in legacy.items()):
            raise ValueError("Bag and snapshot preservation metadata disagree")
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
        # Extract each bag's originals together so its member index is read
        # once, while keeping only one archive descriptor open at a time.
        by_bag = {}
        for file in snapshot["files"]:
            if file["bag_id"] not in required:
                raise ValueError("File references a bag outside the recovery chain")
            by_bag.setdefault(file["bag_id"], []).append(file)
        for bag_id, files in by_bag.items():
            with tarfile.open(archives[bag_id]["path"], "r:*") as tar:
                for file in files:
                    relative = PurePosixPath(file["path"])
                    if relative.is_absolute() or ".." in relative.parts or not str(relative).startswith("data/originals/") or "\\" in str(relative):
                        raise ValueError("Unsafe original path")
                    destination = output / relative
                    destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                    with tar.extractfile(bag_id + "/" + file["path"]) as source, destination.open("xb") as target:
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
    sub.add_parser("scan-inventory", help="Extract a compact saved inventory once; never read originals")
    sub.add_parser("scan-rows", help="Compare only logged metadata rows; never read originals")
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
        elif args.command == "scan-inventory":
            result = scan_inventory(json.load(sys.stdin.buffer))
        elif args.command == "scan-rows":
            result = scan_row_changes(json.load(sys.stdin.buffer))
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
