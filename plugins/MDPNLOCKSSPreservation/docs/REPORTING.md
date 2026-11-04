# Preservation maintenance report

Run this from the installed Providence root, using sudo to run as the web/queue
account `www-data`:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-report --user-id=7
```

The ID is illustrative: supply an active backend administrator who has
`can_preserve_collections`. This is the Providence backend user, separate from
the Unix account `www-data`; substitute the service account if your server uses
a different one. The command reports **eligible, non-deleted archival collection
roots**, including non-public collections, for the configured preservation
destination. Subordinate records remain part of their parent's preserved
hierarchy and freshness check but have no separate report row. Organizational
units are excluded. Native ACL checks must remain enabled. Do not pass
`--disable-item-level-access-control` or run it through `process-task-queue`.

The command needs the plugin enabled and its private ledger initialized. Follow
the [installation/cache steps](../README.md#application-cache-after-installation-or-updates)
if the command or new role actions are missing after installation or an update.
Routine reports do not require cache clearing. The report is read-only and takes
**no `--apply` flag**. It opens SQLite in read-only/query-only
mode and does not queue scans, create group IDs, build bags, write snapshots,
transfer files, change confirmations, or access SFTP credentials. Current
metadata travels to the comparison subprocess through stdin, never a temporary
export file. Output goes to stdout; keep redirected reports private because
they contain collection titles and preservation history.

In MDPN mode, freshness checks read the last validated policy cache without
downloading settings or writing cache files. Refresh it separately when needed:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-refresh-profile --apply
```

New scans and deposits refresh automatically. A missing or invalid cache prevents
a fresh MDPN comparison. Packaging policy, its source/version information and
cache refresh times do not affect catalogue freshness. The original policy
remains protected by each saved snapshot's integrity fingerprint. History-only
reporting does not assert policy freshness.

## Freshness and coverage

By default the command captures current native catalogue metadata and reads
each original file for SHA-256. It compares both the latest complete deposit
and the latest manually confirmed deposit for each preservation root, rather
than trusting the collection's modification time or an earlier saved scan.
Catalogue definitions and the Providence installation profile are included in
this comparison; the BagIt packaging profile is excluded. A packaging-policy
update alone therefore does not require another deposit.
Metadata/file descriptors are captured again afterward; concurrent changes
produce an error, not a clean result. Missing/unreadable originals, a mismatch
against CA's recorded original MD5, or an inconsistent saved fingerprint also
produce visible errors. One failed hierarchy check does not prevent reporting
the remaining collections.

The report reads uncapped ledger history, so a confirmation older than the
editor's 100-job history page is still considered. Failed, cancelled, queued or
running jobs never replace a completed comparison baseline. The latest deposit
attempt is retained in the report. A failed latest attempt gives the overall
status `deposit_failed`, even when an earlier confirmed snapshot still matches
current content. The preservation and latest-complete-deposit freshness columns
continue to describe those earlier successful deposits. A later successful
attempt clears the failure status; a snapshot verification error takes priority.

Organizational markers and hierarchy placement select eligible roots using the
same rule as deposit creation and the editor menu. See
[organizational units and preservation roots](../README.md#organizational-units-and-preservation-roots).
The summary counts excluded organizational units and subordinate records
included with their parents. In organizational mode, unparented records outside
the organizational hierarchy are excluded and reported as warnings.

Adding, removing or changing a child, series, folder or original makes the
archival root's snapshot out of date. A mere external/boundary reference is not
preservation coverage. Several historical child deposits cannot establish
preservation of an undeposited parent. Historical deposits rooted at records
that are now ineligible are retained and produce warnings, without creating
individual report rows or supplying the parent's baseline.

When a saved snapshot cannot be read, its historical child membership is
unknown, including children that may have moved elsewhere. The affected root
has an error, and collections without other evidence require review rather
than being falsely labelled never deposited. Deleted/missing deposit roots
are also reported as warnings. These warnings make the command exit nonzero.

## Output and status meanings

The text table shows the overall status, confirmed-preservation freshness,
latest-deposit freshness, snapshot date, covering root ID and collection title.
JSON includes the exact job IDs, UTC timestamps, coverage,
delivery state, revision, part and supplement counts, change counts in the
checked deposit root, latest attempt, attention flags and totals by status.

| Overall status | Meaning |
| --- | --- |
| `current` | A confirmed deposit matches the eligible root's complete current hierarchy |
| `out_of_date` | A directly checked collection differs from its latest confirmed or complete deposit |
| `deposit_failed` | The latest deposit attempt failed; any earlier completed or confirmed deposit retains its own freshness status |
| `awaiting_confirmation` | Current content has been uploaded; matching preservation has not been confirmed |
| `local_only` | A local package matches, without confirmed remote preservation |
| `never_deposited` | No successful direct or covering deposit exists at this destination |
| `needs_review` | Historical coverage is incomplete or cannot establish the current root's state |
| `not_checked` | History was read without a current-content check |
| `error` | Catalogue, original-file or preservation-state verification failed |

The preservation and latest-deposit columns are deliberately separate. A
current uploaded update can be `awaiting_confirmation` while its last confirmed
preservation is still `out_of_date`. An upload never counts as LOCKSS replication.
The report uses recorded manual confirmation; it does not query nodes, inspect
remote AUs, or validate retained tar archives. Native CA integration and the
existing live preservation pilot still need separate validation.

For machine-readable JSON, suppress the native caUtils heading:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-report --user-id=7 --format=json --quiet
```

For a quick history inventory without hashing originals or exporting current
record metadata:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-report --user-id=7 --history-only
```

History-only mode can show `deposit_failed` from recorded job history, but never
labels content `current` or `out_of_date`. Its recorded
confirmations and delivery states remain available, but freshness is explicitly
`not_checked`. Collections with no history can still be identified, unless
coverage information is damaged. This mode does not require a working Python
binary or readable originals.

Successful reporting returns exit status 0 even when it finds collections to
preserve. Errors or warnings return nonzero. Add `--fail-on-attention` to also
return nonzero for any collection requiring action, including never-deposited,
outdated, unconfirmed, local-only, review-needed or unchecked collections:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-report --user-id=7 --fail-on-attention
```

No recurring execution, email or notification is installed automatically. This
command can be used by an operator's existing maintenance/reporting workflow.

## Cost and limits

Fresh verification reads every original in each checked preservation hierarchy.
Latest and confirmed baselines for the same root share one file-hashing pass.
Overlapping independently deposited roots can read shared originals more than
once. Large repositories should run full checks during an appropriate I/O
window; history-only mode is faster but cannot establish freshness.

Snapshots are still materialized in memory, as in the packaging pilot. The
whole report is not one instantaneous database/filesystem snapshot: each root
has its own checked-at timestamp. The report does not persist a new scan or
update the collection editor's saved scan date. Changing destination identity
starts a separate coverage history; local-test bags cannot satisfy SFTP coverage.
