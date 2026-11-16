# Preservation maintenance report

Run this from the installed Providence root, using sudo to run as the web/queue
account `www-data`:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-report --user-id=7
```

This displays **recorded deposit and confirmation states**. It reads the private
ledger and current collection hierarchy/labels needed to identify eligible roots.
It does not open recovery snapshots, export current record metadata or read
original files. A recorded `confirmed` deposit is not a claim that the collection
has remained unchanged since that deposit.

To omit collections that have never had a deposit attempt at this destination:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-report --user-id=7 --with-history
```

Failed, queued and running attempts remain visible. Scans alone do not count as
deposit attempts. This filter does not mean "confirmed deposits only."

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
transfer files, change confirmations, or access SFTP credentials. During an
explicit verification, current metadata travels to the comparison subprocess
through stdin, never a temporary export file. Output goes to stdout; keep redirected reports private because
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

Content verification is opt-in:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-report --user-id=7 --with-history --verify
```

Only `--verify` captures current native catalogue metadata and reads each
original file for SHA-256. It compares both the latest complete deposit
and the latest manually confirmed deposit for each preservation root, rather
than trusting the collection's modification time or an earlier saved scan.
Freshness compares collection, object and representation records/attachments,
their direct relationships and original-file identities/checksums. Descriptions,
labels, repeated attributes and access metadata on those records are included.
Adding or removing a member, editing an object or representation, changing a
direct relationship or replacing an original therefore requires attention.

Related authority records remain recovery context. Adding a subject heading to
a related person's record does not make every linked collection stale. Editor
layouts, field validation/display settings, applicability rules, packaging policy,
unused vocabulary/relationship definitions, hierarchy indexing and view counts
also do not affect preservation status. The full configuration and authority
data remain in the saved snapshot; later deposits capture their current state.

The archival root's own sort order among sibling collections is recovery
context. Changing that value alone does not make its preservation stale.
Ordering of subordinate collections, objects and direct relationships within
the preserved hierarchy remains significant, as does moving its root to a
different parent.

The comparison retains definitions actually needed to interpret the collection's
values: used field codes/datatypes and container ancestry, list bindings, selected
terms and their ancestors/labels, used relationship types/labels and locales.
A changed assigned term or used relationship meaning is thus a collection
metadata change even when its stored numeric ID has not changed. Unused terms
or indirect authority headings do not enter this comparison. JSON includes an
informational `recovery_context_changed` flag; this does not change the status,
require attention, produce a text warning or require a supplemental deposit.
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
An unchanged supplemental attempt is a successful no-op and does not replace the
completed baseline. Reports also recognize the exact historical empty-supplement
error as `unchanged`, retaining `recorded_status` in JSON without rewriting the
stored job. Real failures are not suppressed. Confirmation and freshness remain
separate: confirmation is evidence about that deposited version, rather than a
claim that all later installation settings still match it.

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

During verification, when a saved snapshot cannot be read, its historical child membership is
unknown, including children that may have moved elsewhere. The affected root
has an error, and collections without other evidence require review rather
than being falsely labelled never deposited. Deleted/missing deposit roots
are also reported as warnings. These warnings make the command exit nonzero.

## Output and status meanings

By default, the text table shows the overall recorded state, confirmed
preservation, latest completed deposit's delivery state, snapshot date and
collection title. A later failed or pending attempt appears in the overall
state without hiding an earlier confirmation. Confirmed collections do not
require attention merely because content has not been rechecked.

With `--verify`, the preservation and latest-deposit columns show current-content
freshness instead of recorded delivery states.
JSON includes the exact job IDs, UTC timestamps, coverage,
delivery state, revision, part and supplement counts, change counts in the
checked deposit root, latest attempt, attention flags and totals by status.
The text table lists preservation roots, so it omits the redundant `VIA ROOT`
column. JSON retains covering root IDs and inherited-coverage information for
interpreting historical deposits and recovery lineage.

| Overall status | Meaning |
| --- | --- |
| `confirmed` | Recorded LOCKSS confirmation; current content has not been rechecked |
| `queued` / `running` | The latest deposit attempt is pending or in progress; earlier confirmations remain visible |
| `current` | With `--verify`, a confirmed deposit matches the root's complete current hierarchy |
| `out_of_date` | With `--verify`, current content differs from a confirmed or complete deposit |
| `deposit_failed` | The latest deposit attempt failed; any earlier completed or confirmed deposit retains its own freshness status |
| `awaiting_confirmation` | The latest complete deposit was uploaded but has not been confirmed in LOCKSS |
| `local_only` | The latest complete deposit was built locally without a remote upload |
| `never_deposited` | No successful direct or covering deposit exists at this destination |
| `needs_review` | Historical coverage is incomplete or cannot establish the current root's state |
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

`--history-only` remains available as an explicit alias for the default recorded
state inventory:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-report --user-id=7 --history-only
```

Recorded-state reports never label freshness `current` or `out_of_date`.
JSON retains `freshness_check: "history_only"`, `freshness: "not_checked"` and
null content-check timestamps while reporting actual confirmation/delivery
states. They require neither a Python binary, an MDPN policy cache nor readable
recovery snapshots/originals. Do not combine `--history-only` with `--verify`.

## Arguments

| Argument | Meaning |
| --- | --- |
| No extra arguments | Show recorded states for all eligible roots at the configured destination |
| `--with-history` | Only list roots with at least one deposit attempt; include failures and pending attempts, exclude scan-only histories |
| `--verify` | Check current metadata and original-file SHA-256 values; potentially expensive |
| `--history-only` | Explicitly select the default recorded-state behavior; conflicts with `--verify` |
| `--format=text`, `-f=text` | Human-readable table; the default |
| `--format=json`, `-f=json` | Structured report; combine with `--quiet` |
| `--quiet` | Native caUtils option suppressing its banner, not the report |
| `--fail-on-attention` | Also exit nonzero when a listed collection needs attention |
| `--user-id=N`, `-u=N` | Active Providence backend administrator used for authorization |
| `help` | Positional native help request; for example, append `help` after the command |

The JSON `with_history_only` flag records whether the history filter was used.
Summary collection/status/attention counts apply to listed roots. Organizational
and subordinate counts still describe the hierarchy. Warnings about historical
deposits remain visible; the filter omits placement warnings for records with
no deposit attempts.

The native caUtils parser also accepts `--hostname=HOST` and `--setup=PATH` for
installation selection. These are administrator options and are normally
unnecessary when running from the installed application root. Although the
parser exposes `--disable-item-level-access-control`, this preservation command
rejects disabled ACLs. There is no `--apply` option for this read-only report.

Successful reporting returns exit status 0 even when it finds collections to
preserve. Errors or warnings return nonzero. Add `--fail-on-attention` to also
return nonzero for any listed collection requiring action, including
never-deposited, outdated, unconfirmed, local-only, failed, pending or
review-needed collections. Recorded `confirmed` state does not require attention
solely because no content check was requested:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-report --user-id=7 --fail-on-attention
```

No recurring execution, email or notification is installed automatically. This
command can be used by an operator's existing maintenance/reporting workflow.

## Cost and limits

The default recorded-state report avoids the large recovery snapshots and all
original-file reads. Explicit `--verify` reads every original in each checked
preservation hierarchy.
Latest and confirmed baselines for the same root share one file-hashing pass.
Overlapping independently deposited roots can read shared originals more than
once. Large repositories should run full checks during an appropriate I/O
window; history-only mode is faster but cannot establish freshness.

Verification snapshots are still materialized in memory, as in the packaging
pilot. The whole verification report is not one instantaneous database/filesystem
snapshot: each root has its own checked-at timestamp. The report does not persist a new scan or
update the collection editor's saved scan date. Changing destination identity
starts a separate coverage history; local-test bags cannot satisfy SFTP coverage.
