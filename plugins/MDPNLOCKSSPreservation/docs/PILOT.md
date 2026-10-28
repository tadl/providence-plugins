# Pilot acceptance checklist

Source/standalone tests have passed only when reported by the current change.
This checklist is for the actual installation; it does not authorize deployment,
production exports, record edits, credential retrieval or transfers.

## Local mode on an isolated catalogue

- Verify PHP/Python versions and extensions for the CLI account and web runtime.
- Confirm the private state directory is outside the web root/source checkout,
  mode 0700, on local storage, with appropriate free space and a private backup.
- Initialize the ledger explicitly, enable only this plugin and grant the two
  role actions deliberately. Keep transport local and use synthetic test data.
- Confirm the collection inspector link and keyboard-accessible form work.
- With an authorized archivist, package a collection containing child
  collections, object components, duplicate `idno` values, shared objects and
  representations, non-public originals, multilingual/repeated/container
  metadata, file/media attributes, captions, sidecars and multifile originals.
- Run through the actual native task queue and its isolated PHP bootstrap.
- Confirm originals are exported despite public access/status values; reject
  an unauthorized member instead of omitting it. Revoke the requester's role
  while a job is queued and verify it fails without producing a new baseline.
- Verify native read-only transaction behavior and clean rollback on failure.
- Validate generated bags using an independent BagIt implementation and compare
  the runtime MDPN requirements with the coordinator's current settings.
- Change labels, attributes, hierarchy, relation type/rank/attributes and files;
  remove a member; create supplements. Inspect the complete change inventory.
- Run a scan on unchanged content and verify no changes; request a new full
  deposit and verify its recovery chain is independent of earlier deposits.
- Run `mdpn-preservation-report --user-id=ADMIN_ID` and check all non-deleted
  collections, parent coverage, new/unpreserved children, stale confirmed
  preservation, uploaded-but-unconfirmed updates, failures and history-only
  results. Verify no preservation files/ledger/jobs change and review both text
  and JSON output. See [reporting](REPORTING.md) for exit flags and I/O costs.
- Lower the part-size limit for small test fixtures, exercise multiple parts,
  and verify the serialized byte limit, repeated metadata and per-job Bag-Count.
- Simulate missing/unreadable originals, source edits during capture, insufficient
  disk space, worker interruption, abandoned queue entries and partial failures.
- Reconstruct the latest state from a multipart full plus several supplements;
  verify removed files are absent and every current original matches SHA-256.
- Import the reconstructed catalogue into another fresh CA installation using
  a reviewed native importer/crosswalk. Compare all record/relationship counts,
  hierarchy, labels, attribute values, media/ownership and access settings.

## Provider pilot after local acceptance

- Obtain MDPN's sender-IP allowance and independently verify its SSH host key.
- Confirm path, permissions, `.partial` exclusion, rename/finalization behavior,
  package naming, custom tags, grouped bags and duplicate-submission handling.
- Provision the private credentials file outside the web root and Git, mode
  0600, with the service account as its only reader.
- Configure a new SFTP destination identity. Its first deposit must be full;
  local-test bags cannot satisfy dependencies for this destination.
- Send a small approved pilot deposit. Compare archive names, sizes and hashes
  at staging; simulate a disconnect and verify prefix-checked resume, immutable
  retries and refusal of a different existing final/partial archive.
- For multipart jobs, fail a later part and verify the baseline does not
  advance. Reconcile parts already consumed by ingestion before retrying.
- Have node hosts crawl the new AUs. Record actual node/crawl evidence manually;
  a successful staging upload must remain labelled unconfirmed until then.
- Retrieve the bags from preservation storage and repeat offline recovery and
  fresh-CA reimport acceptance. Review archive retention and routine state backup.

## Known first-version boundaries

- Real Providence/MySQL/media integration, isolated queue bootstrap and MDPN
  acceptance require this pilot; the synthetic suites cannot establish them.
- No native CA write/import command is supplied yet. The recovery command
  reconstructs the complete current snapshot and files, not a running service.
- Change scans are explicit; no automatic recurring scan or save-time dirty
  indicator is installed. A scan/deposit always recaptures content and file fixity.
- Histories show 100 recent jobs and 200 change entries per page. Complete data
  remains in the private ledger/results and bag metadata.
- Catalogue snapshots are materialized in memory. The archive validator caps
  each metadata file at 512 MiB. Establish practical collection size/memory
  limits in the pilot before processing very large production catalogues.
- SFTP is tested with a synthetic protocol fake. Readback verifies bytes but
  adds download traffic; confirm this is acceptable to the staging operator.
- No automatic cleanup, running-job cancellation, replication polling, S3,
  arbitrary BagIt profiles or cross-server/shared-NFS ledger operation is supplied.
