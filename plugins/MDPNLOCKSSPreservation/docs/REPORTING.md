# Preservation maintenance report

Run this from the installed Providence root as the normal CLI service account:

```sh
php support/bin/caUtils mdpn-preservation-report --user-id=7
```

The ID is illustrative: supply an active backend administrator who has
`can_preserve_collections`. The command reports **all non-deleted collections**,
including non-public collections and nested children, for the configured
preservation destination. Native ACL checks must remain enabled. Do not pass
`--disable-item-level-access-control` or run it through `process-task-queue`.

The command needs the plugin enabled and its private ledger initialized. It is
read-only and takes **no `--apply` flag**. It opens SQLite in read-only/query-only
mode and does not queue scans, create group IDs, build bags, write snapshots,
transfer files, change confirmations, or access SFTP credentials. Current
metadata travels to the comparison subprocess through stdin, never a temporary
export file. Output goes to stdout; keep redirected reports private because
they contain collection titles and preservation history.

## Freshness and coverage

By default the command captures current native catalogue metadata and reads
each original file for SHA-256. It compares both the latest complete deposit
and the latest manually confirmed deposit for each preservation root, rather
than trusting the collection's modification time or an earlier saved scan.
Metadata/file descriptors are captured again afterward; concurrent changes
produce an error, not a clean result. Missing/unreadable originals, a mismatch
against CA's recorded original MD5, or an inconsistent saved fingerprint also
produce visible errors. One failed hierarchy check does not prevent reporting
the remaining collections.

The report reads uncapped ledger history, so a confirmation older than the
editor's 100-job history page is still considered. Failed, cancelled, queued or
running jobs never replace a completed comparison baseline. The latest deposit
attempt is reported separately; a later failed attempt requires attention even
when an earlier confirmed snapshot still matches current content.

A child listed in a parent deposit's saved collection membership is covered by
that deposit. If the entire parent snapshot is unchanged, that is sufficient
evidence that the child is current. If the parent has changed, the child is
marked `needs_review`: a sibling's edit does not prove that this particular
child changed. A separately confirmed current child deposit can establish
current preservation even when its parent's deposit is outdated.

A child added after the parent's snapshot is not covered by that old deposit.
A mere external/boundary reference to another collection does not count as
coverage. Coverage is not inferred by combining several child deposits into
an undeposited parent hierarchy.

When a saved snapshot cannot be read, its historical child membership is
unknown, including children that may have moved elsewhere. The affected root
has an error, and collections without other evidence require review rather
than being falsely labelled never deposited. Deleted/missing deposit roots
are also reported as warnings. These warnings make the command exit nonzero.

## Output and status meanings

The text table shows the overall status, confirmed-preservation freshness,
latest-deposit freshness, snapshot date, covering root ID and collection title.
JSON includes the exact job IDs, UTC timestamps, direct/inherited coverage,
delivery state, revision, part and supplement counts, change counts in the
checked deposit root, latest attempt, attention flags and totals by status.

| Overall status | Meaning |
| --- | --- |
| `current` | A confirmed direct or parent deposit matches fresh content |
| `out_of_date` | A directly checked collection differs from its latest confirmed or complete deposit |
| `awaiting_confirmation` | Current content has been uploaded; matching preservation has not been confirmed |
| `local_only` | A local package matches, without confirmed remote preservation |
| `never_deposited` | No successful direct or covering deposit exists at this destination |
| `needs_review` | A covering parent changed, or historical coverage is incomplete |
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
php support/bin/caUtils mdpn-preservation-report --user-id=7 --format=json --quiet
```

For a quick history inventory without hashing originals or exporting current
record metadata:

```sh
php support/bin/caUtils mdpn-preservation-report --user-id=7 --history-only
```

History-only mode never labels content `current` or `out_of_date`. Its recorded
confirmations and delivery states remain available, but freshness is explicitly
`not_checked`. Collections with no history can still be identified, unless
coverage information is damaged. This mode does not require a working Python
binary or readable originals.

Successful reporting returns exit status 0 even when it finds collections to
preserve. Errors or warnings return nonzero. Add `--fail-on-attention` to also
return nonzero for any collection requiring action, including never-deposited,
outdated, unconfirmed, local-only, review-needed or unchecked collections:

```sh
php support/bin/caUtils mdpn-preservation-report --user-id=7 --fail-on-attention
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
