# Recovery format 1

Each bag is BagIt 1.0 with `bagit.txt`, `bag-info.txt`,
`manifest-sha256.txt`, `tagmanifest-sha256.txt`, and these payload files:

```text
data/
  README.txt
  metadata/
    snapshot.json
    metadata-schema.json
    installation-profile.xml
    collections.csv
    objects.csv
    representations.csv
    relationships.csv
    files.csv
    changes.csv
  originals/<logical-file-key-sha256-prefix>/<safe-filename>
```

The installable XML is generated from an explicit native exporter allowlist:
locales, vocabularies, metadata definitions, UIs, relationship types, roles,
groups, search forms and metadata dictionary. User logins/passwords and server
configuration are not exported. Raw definition rows retain the original IDs as
well as codes and labels. The snapshot records Providence version/schema
revision, the export table/foreign-key descriptions and packaging profile.

## Read the catalogue snapshot

`snapshot.json` is authoritative. Its format marker is
`mdpn-ca-preservation-1`. It includes:

- `repository_id` and the root `collection_id` as source identifiers.
- `captured_at`, the snapshot fingerprint, deposit ID/revision/mode and parent.
- `records`: native table -> source primary key -> record envelope.
- `relationships`: linking table -> source primary key -> record envelope.
- `schema`: original definition rows, table/foreign-key descriptions, native
  installation-profile XML and version/profile information.
- `external_references`: table/ID boundary references outside this hierarchy.
- `files`: complete current file inventory.
- `changes`: records/relationships/files added, changed or removed versus the
  compared baseline, plus schema changes.
- `chain`: the independent full deposit and subsequent supplements required to
  reconstruct this version, with each part's bag ID and filename. Earlier
  deposits' archive checksums are carried forward; the current archive checksums
  are in the private ledger/result file and the downloadable recovery roadmap.

Record envelopes contain `row`, `labels`, `attributes` (native attribute rows
and all native value rows) and `acl`. Every field is retained rather than using
the editor's display template. Binary/non-UTF-8 strings use a single-key
`{"$base64": "..."}` object; decode to the exact original bytes where needed.

CSV cells are JSON values. This deliberately preserves nulls, repeated values,
multilingual labels and metadata containers; JSON strings also prevent a value
beginning with `=` from becoming a spreadsheet formula. Read CSV using a CSV
parser, then decode each cell with a JSON parser. Do not split rows on commas or
newlines, and do not infer list values from a display delimiter.

Each file inventory entry has a stable logical `key`, original filename,
owning table/row/field or attribute/value/element IDs, safe payload `path`, byte
size, SHA-256 and the exact `bag_id` holding those bytes. Original names can be
repeated or contain punctuation/Unicode; packaged paths are distinct and safe.
Names with invalid UTF-8 retain the exact filename bytes in a base64 wrapper.
Source server paths are not used as reconstruction destinations.

Raw media/file descriptors in native rows are historical metadata, **not
instructions to reopen absolute paths from the old server**. Bind imported
originals using `files`, then let Providence create new local storage descriptors
and derivatives.

## Retrieve and reconstruct

1. Select the target deposit by its ID, not simply the newest-looking filename.
   Use its recovery roadmap, or read `data/metadata/snapshot.json` from any part
   of that deposit, to identify the full chain.
2. Retrieve **every part of every listed deposit** from preservation storage.
   A supplemental bag alone is intentionally insufficient. A later independent
   full deposit needs only its own parts.
3. Keep these source archives immutable. Use a private recovery directory on a
   host with Python 3.9+ and space for the reconstructed originals.
4. Validate and reconstruct using this plugin's standalone script:

   ```sh
   python3 worker/preservation.py verify /private/retrieved/*.tar
   python3 worker/preservation.py recover --deposit=DEPOSIT_ID --output=/private/recovered /private/retrieved/*.tar
   ```

The output directory must not already exist. The command rejects traversal,
links, duplicate members, missing/mismatched parts and inconsistent lineage. It
verifies payload/tag checksums and snapshot fingerprints before reconstruction,
then verifies the size and SHA-256 of each reconstructed original. No tar member
is extracted by blindly applying its pathname to the filesystem.

Successful output contains `catalogue-snapshot.json`, `data/originals/` and a
README. Only the latest inventory's originals are reconstructed. Older/deleted
versions remain in the retrieved bags. If a failure occurs after output starts,
`RECOVERY_INCOMPLETE.txt` marks the partial result; it must not be treated as a
successful recovery. Inspect or discard it before retrying to a fresh directory.

The SQLite ledger is helpful for retrieval and confirmation history, but is not
required for this offline reconstruction. Keep a trusted source copy of this
script/documentation outside the running Providence server.

## Reimport into Providence

Do this in an isolated new installation first. The plugin does not yet ship an
automatic native database importer. File/snapshot reconstruction is tested;
restoring a functioning CA catalogue remains a separate pilot acceptance step.

A native importer should:

1. Provision compatible Providence code/plugins and install/reconcile the saved
   XML profile. Verify the native metadata datatypes and relationship types.
2. Create explicit crosswalks from `(repository_id, table, source_id)` to new
   IDs. Map source locales, vocabulary items, elements and relationship types by
   their saved codes/definitions. Do not use `idno` as a unique lookup key.
3. Restore scoped collections, hierarchy and object components in parent-first
   order, preserving their original IDs in the crosswalk. Treat the selected
   root's original parent outside the preserved subtree as a boundary mapping.
4. Restore related authorities and their ancestors; reconcile external boundary
   references using other preserved collections or reviewed placeholders.
5. Restore labels, intrinsic values and repeated/container attributes using CA
   model APIs. Historic dates, authority/list IDs and media/file attributes need
   their datatype-specific native input conversions.
6. Attach each inventory original once to its correct representation or field,
   using the exact original filename and all owning IDs from the crosswalk.
   Recreate shared representation links, primary flags, ranks and components.
7. Restore relationships and relationship attributes after their endpoints
   exist. Preserve relationship direction and ordering.
8. Restore public `access`/status values, and deliberately remap backend users,
   groups and ACL rows. Original user/group IDs are historical references; the
   package contains no user logins/passwords. Never recreate unsafe world access
   simply because a backend identity cannot be mapped.
9. Rebuild derived media/search indexes using normal CA procedures, then compare
   record/relationship counts, labels, attributes, hierarchy, file checksums and
   access behavior with the snapshot.

The installable profile and raw rows are designed to retain what that importer
needs. Their presence is not evidence that a fresh-CA round trip has passed.

## Snapshot consistency and change semantics

The collector reads native rows in a repeatable-read, read-only transaction on
a dedicated connection. File copies verify descriptor identity, size and
nanosecond modification/change times; original MD5 values recorded by CA are
also checked when available. Those MD5 values are source sanity checks, not the
preservation manifest algorithm. All preserved files receive SHA-256 hashes.

After packaging and validating the serialized archives, the service reloads
requester permissions and captures metadata/file descriptors again. Changes
during capture fail the operation instead of silently advancing its baseline.
Subsequent edits belong to the next scan/deposit. This is an application-level
snapshot protocol, not an atomic filesystem/database backup.

Fingerprints include catalogue rows, labels, attributes, relationships, schema,
external references and original file identities/checksums. Capture timestamps,
bag locations and deposit identifiers are excluded, so a fresh independent full
deposit of unchanged content has the same content fingerprint. Changing a bag
contact does not itself make catalogue content a supplemental change.
