# File Health

Independent integrity monitoring for Providence, using the **unmodified native
`check-media-fixity` implementation** and its upload-time MD5 signatures. No
preservation deposit, LOCKSS configuration, or other application plugin is needed.

This first version checks **representation media**, with `original` as the default
version. It stores completed checks, unresolved issues, and sweep history in a
private SQLite database. An optional dashboard widget and the plugin's File Health
page display saved results; neither reads media or starts work during a web request.

Source only: installing, initializing, scheduling and live validation are separate
operator steps. Shared configuration defaults to disabled. No production files,
database tables, services, caches or schedules are changed by this repository.

## Scope and interpretation

- All non-deleted representation records are eligible, including private records,
  regardless of collection membership or preservation history. The UI and CLI
  require an **active native Providence administrator** because results cover the
  entire installation. There are no institution IDs, account IDs or collection
  mappings in source. Non-administrator views are deliberately not supplied yet.
- `versions = original` checks the uploaded representation original. A comma-separated
  list can opt into other native version names. An unavailable version is unverified,
  not a successful check. Generated derivatives can be regenerated; damage to an
  original requires a known-good replacement.
- **Metadata File/Media attributes and representation multifiles are excluded.**
  The native attribute branch has no per-value filter for bounded checking, and
  the native representation branch does not enumerate multifiles. A completed
  sweep is evidence only for the configured representation versions, never a
  claim that every file on the server is healthy. No directory crawl is performed.
- A missing descriptor, checksum, local path, external reference, icon/substituted
  version, queued processing or unsupported multipage version is **unverified**.
  Missing local bytes, unreadable files and mismatches have separate statuses.
- MD5 uses Providence's existing ingest evidence to detect accidental corruption.
  A match is not a format validation, malware scan, backup test, authenticity
  signature, or evidence of historical correctness before the original ingest.
- Status is dated evidence. New uploads or later edits are not instantly checked.
  Recorded target counts are history, not a live inventory. Check dates, unfinished
  sweeps, overdue results and the scheduler heartbeat remain visible.

## How checking works

The scheduled `file-health-check` command invokes
`CLIUtils::check_media_fixity()` with the native `Zend_Console_Getopt`, one known
representation/version at a time. This is the same implementation as
`caUtils check-media-fixity`; it does not parse translated console output or the
native CSV report. The native function's return value alone is not success:
native error counts are checked as well. Because that command exposes no positive
file-read count and can silently skip files, a zero-error result receives a
supplementary MD5 read against the same saved upload signature. Healthy files
therefore require **two reads**. No new checksum baseline is established.

The adapter checks coverage before invoking the native checker and compares the
media descriptor and file identity before/afterward. Concurrent changes produce
`changed`, rather than a clean result. This is an application-level race check,
not an atomic database/filesystem snapshot; metadata times have the resolution
provided by PHP/native storage. Run during quieter I/O periods when practical.

A sweep pins its configured versions and highest representation ID. Each invocation
advances at most `batch_size` representations and stops between files after the
soft `max_seconds` budget. One large file can exceed that budget. Each representation's
results and cursor commit in the same transaction. A crash leaves the sweep
incomplete; the next invocation resumes from the last committed representation.
A database/inventory failure marks the sweep failed. Later sweeps retain earlier
issues and history. An OS lock prevents concurrent scanner processes.

The native checker is run directly in the isolated CLI, with a validated active
backend administrator and native ACL context. Do not route it through the generic
`process-task-queue`, which disables ACLs, and do not supply an ACL-disabling flag.
No native queue handler, external daemon or preservation worker is used.

## Installation preparation

Requires Providence 2.0.10-compatible native CLI interfaces, PHP 8.3+, PDO SQLite,
and readable local representation media. Standalone tests cover the 2.0.10 source;
an authorized staging check is still required for other versions and the actual
installation's storage adapters, MySQL permissions, routing and dashboard.

1. Copy this directory to `app/plugins/fileHealth/`, preserving any existing
   `conf/plugin.local.conf`. Do not copy tests into a public web directory.
2. Create a private state directory **outside the web root and Git checkout**, owned
   by the service account. On a typical Linux installation:

   ```sh
   sudo install -d -m 0700 -o www-data -g www-data /var/lib/providence-file-health
   ```

3. Copy `conf/plugin.local.conf.example` to the installation's ignored
   `conf/plugin.local.conf`; review paths, intervals and batch size, then enable it.
4. From the installed Providence root, initialize the plugin ledger. The example
   administrator ID is illustrative; substitute an active authorized account:

   ```sh
   sudo -u www-data -- php support/bin/caUtils file-health-init --user-id=7 --apply
   sudo -u www-data -- php support/bin/caUtils file-health-check --user-id=7 --apply --quiet
   ```

5. Install the optional companion `fileHealth` dashboard widget from the independent
   [providence-widgets repository](https://github.com/tadl/providence-widgets).
   Follow its README for dashboard configuration and activation. The plugin also
   adds a File Health link to the administrator Manage menu. The widget explicitly
   depends on this plugin; neither depends on preservation.

If native cached plugin/menu definitions need refreshing, use the installation's
reviewed application-cache procedure during an approved maintenance window.
This plugin never clears caches or restarts services automatically.

## Scheduling

Install a cron entry or systemd timer separately, using the service account. For
example, a service-account crontab could advance one batch every five minutes:

```cron
*/5 * * * * cd /path/to/providence && /usr/bin/php support/bin/caUtils file-health-check --user-id=7 --apply --quiet >> /private/log/file-health.log 2>&1
```

Those paths and the account ID are synthetic installation placeholders. Create
the private log directory with suitable ownership/permissions and arrange log
rotation. Do not use `--restart` in the recurring schedule. The command returns
failure when unresolved issues exist, even if its batch finished successfully;
continued scheduled invocations still advance the sweep.

| Setting | Default | Meaning |
| --- | --- | --- |
| `versions` | `original` | Comma-separated native representation version names. |
| `batch_size` | `25` | Maximum representations per invocation. |
| `max_seconds` | `60` | Soft time limit, checked between representations. |
| `check_interval_seconds` | `604800` | Start another sweep this many seconds after the preceding completed sweep's **start**. |
| `worker_stale_seconds` | `3600` | Warn when no scheduled invocation has updated its heartbeat within this period. |

Tune the schedule and batch size so an entire sweep finishes comfortably within
the selected interval. The interval is an operator policy, not a universal
preservation recommendation. If a sweep takes longer, the next one becomes due
immediately. A heartbeat only proves the scheduler ran; it does not imply a
successful or completed sweep. Configure outside monitoring if you need alerts
when nobody opens Providence; this version does not send email or notifications.

For saved status without reading media or modifying the ledger:

```sh
sudo -u www-data -- php support/bin/caUtils file-health-status --user-id=7 --quiet
```

## Reviewing damage and replacement candidates

1. Review the affected representation and its dated check history. Preserve the
   suspect bytes until a recovery decision is made. A checksum failure does not
   authorize automatic deletion, replacement or restoration.
2. Retrieve a candidate from a backup, an original workstation or a preservation
   copy. Verify it **before uploading**, against the expected checksum saved with
   the original issue:

   ```sh
   sudo -u www-data -- php support/bin/caUtils file-health-verify-candidate --user-id=7 --issue-id=3 --file=/private/recovery/candidate.tif --quiet
   ```

   This only reads the candidate; it does not upload it, change Providence's
   signature or resolve an issue. An issue without an original expected checksum
   cannot establish a verified recovery by this method.
3. Restore through the site's reviewed media workflow. A normal re-upload can
   change native signatures and file paths, so preserve the old issue evidence.
4. Start a new bounded sweep immediately after the repair, then let normal
   scheduled invocations continue it:

   ```sh
   sudo -u www-data -- php support/bin/caUtils file-health-check --user-id=7 --apply --restart --quiet
   ```

5. A successful native check against the **same recorded file identity** resolves
   that issue automatically. A newly uploaded generation does not silently erase
   the previous generation's damage. Once the current file has a recent successful
   check, an administrator can explicitly review the older issue:

   ```sh
   sudo -u www-data -- php support/bin/caUtils file-health-review --user-id=7 --issue-id=3 --note='Verified replacement against saved ingest checksum; reviewed the new generation.' --apply
   ```

Reviewing closes an issue; it does not claim the old damaged bytes were repaired.
All recorded checks, original expected checksums and explicit administrator reviews
remain, including reviews of an issue that later reopens. Complete application
backups, independent copies, metadata preservation and tested restorations still
need their own procedures.

## Private state and testing

Back up the entire private state directory while the scanner is idle, including
`health.sqlite`; keep its ownership and private permissions. It contains record
labels and historical checksum evidence. It is independent of application caches
and preservation state. There is no automatic purge of history in this version;
monitor growth and agree retention before adding one. Never commit this state or
private logs. Removing source does not remove the private ledger.

From the source monorepo root:

```sh
bash scripts/check.sh
```

File Health tests use synthetic records/files and temporary private SQLite state.
The native suite executes the real trusted Providence Maintenance trait and
`caGetOption` with a real Zend parser, without bootstrapping an installation or
reading connection settings. Set `PROVIDENCE_REFERENCE_ROOT` for another trusted
reference checkout and `ZEND_REFERENCE_ROOT` to the directory containing `Zend/`.
The workspace's existing sibling vendor library is detected automatically. CI
checks out both reference sources at pinned commits. This adds no plugin runtime
dependency beyond Providence's existing native CLI dependencies.
