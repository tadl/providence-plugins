# File Health

A read-only Providence interface for **native `check-media-fixity` patrols and
reports**. No preservation deposit, LOCKSS service, or other plugin is required.
The optional dashboard widget shows recent patrols and links to their reports.
The page's native sidebar links to patrol status, check history and the dashboard.

The standalone CLI adapter runs the native command once, records its start,
heartbeat, exit and report availability, and leaves email delivery to Providence.
It does not hash media, create checksum baselines, infer verified files from
native reports, or run an additional scanner.

Source changes do not install, enable or schedule this plugin. Shared configuration
defaults to disabled; deployment and installed validation are separate steps.

## What the status means

| Status | Meaning |
| --- | --- |
| Running | A journaled native invocation is active. An overdue heartbeat is shown separately. |
| No errors reported | Native exit zero and a nonempty report were observed. This does not establish that every file was checked. |
| Needs attention | Native exit was nonzero and a report exists. This may include checksum findings, mail failures or other native errors; review the report and private log. |
| Failed | A normal native result/report was not obtained. |
| Interrupted | Completion was not recorded, or the adapter was interrupted. This never implies success. |
| Untracked report | An existing native text report is available, but its execution outcome and scope were not journaled. Its file date is not a confirmed patrol completion time. |

The native checker can skip files without a checksum or resolvable path. Reports
list failures; an empty or header-only report is not evidence of individual file
verification. There are no per-file verified counts, issue-resolution workflow or
recovery claims in this plugin. Backups and tested restorations remain separate.

Scope is saved with each tracked invocation. Defaults match an originals patrol:
`kinds = ca_object_representations`, `versions = original`. Other native kinds or
versions can be passed unchanged; blank versions requests all stored versions.
Native coverage limits, including representation multifiles, remain unchanged.
Older reports with no scope are never assumed to use the current configuration.

## Reuse existing patrol data

Point `state_directory` at the existing private patrol directory, containing a
`reports/` directory of native `.txt` reports. The plugin can list those reports
without initializing a database or changing files. Reports without wrapper
journals are labeled **Untracked report**. The plugin does not infer successful
completion from their filenames or contents; they do not satisfy its tracked
patrol freshness indicator.

If a direct native patrol holds the shared lock without a run journal, the
interface shows that the patrol lock is in use. It does not invent a start time,
scope, exit result or successful completion for that invocation.

For future complete run history, replace the existing cron command with the
standalone adapter below. Do not keep a second patrol schedule. The adapter and
the direct native patrol share `state_directory/patrol.lock`, so manual and
scheduled jobs cannot overlap when both use that lock.

## Prepare the adapter and interface

Requires PHP 8.3 CLI with `proc_open` and `pcntl`, a working native Providence CLI,
and private service-account storage outside the web root and Git checkout.
No SQLite database, Composer package, checksum tool, or core patch is added.
Web access requires a logged-in, active native Providence administrator because
reports can describe private records across the installation.

1. Install this plugin at `app/plugins/fileHealth/`, preserving any existing
   `conf/plugin.local.conf`. Keep tests outside the deployed public tree.
2. Use the existing native patrol state directory or create one. For example:

   ```sh
   sudo install -d -m 0700 -o www-data -g www-data /var/lib/providence-fixity
   ```

3. Copy `conf/plugin.local.conf.example` to the installation's ignored
   `conf/plugin.local.conf`; set the shared directory, expected scope, frequency
   and grace period. Enable the interface when ready.
4. Install the optional `fileHealth` widget from the separate
   [providence-widgets repository](https://github.com/tadl/providence-widgets).
   The administrator Manage menu also opens the report/history page.

The standalone adapter can be installed privately without enabling the web
plugin. Keep its `support/` and `lib/` relative layout intact. It loads no
application configuration itself; only its native child bootstraps Providence.

If plugin/menu discovery requires refreshing caches, follow the site's reviewed
maintenance procedure. This plugin never purges caches or restarts services.

## Run one full native patrol

Run as the existing patrol's service account. Substitute installation paths and
the authorized recipient; values here are illustrative:

```sh
sudo -u www-data -- php /path/to/fileHealth/support/run_native_patrol.php \
  --application-root=/path/to/providence \
  --state-directory=/var/lib/providence-fixity \
  --kinds=ca_object_representations --versions=original \
  --email=operator@example.org
```

The adapter creates private `runs/`, `reports/` and `logs/` directories as needed.
It journals a fresh opaque ID, invokes unmodified `caUtils check-media-fixity`
once, and records its outcome after it exits. Only the native command reads
media or sends email. Omitting `--email` disables native email for that invocation.
Native sender/SMTP configuration remains installation-local.

The adapter returns nonzero when the native result needs review. Stdout/stderr
is drained to a bounded private log, never rendered by the interface. Reports
remain complete and downloadable by administrators. SIGINT/SIGTERM are forwarded
to the child and journaled as interrupted. The child retains the patrol lock if
the wrapper is killed unexpectedly.

An existing direct native cron command can still be run manually with its same
lock, scope and report directory. Its report appears as untracked until run
journaling is adopted. Wrapper metadata is not created retroactively.

## Schedule a single patrol

Replace the existing patrol command during an authorized deployment. Example
system cron entry (UTC server; illustrative paths and recipient):

```cron
0 8 * * 0 www-data umask 077 && /usr/bin/php /path/to/fileHealth/support/run_native_patrol.php --application-root=/path/to/providence --state-directory=/var/lib/providence-fixity --kinds=ca_object_representations --versions=original --email=operator@example.org
```

Do not put an outer `flock` on `patrol.lock` around the adapter: it takes that lock
itself. A direct native command still needs its existing external lock. Do not
invoke the adapter from the generic task queue or widget rendering. Measure full
patrol duration before choosing a different cadence or I/O window.

| Interface setting | Default | Meaning |
| --- | --- | --- |
| `state_directory` | `/var/lib/providence-fixity` | Shared native reports and run journals. |
| `kinds` | `ca_object_representations` | Expected native kinds for tracked-patrol freshness. |
| `versions` | `original` | Expected versions; blank means native stored versions. |
| `patrol_interval_seconds` | `604800` | Expected time between tracked patrol starts. |
| `overdue_grace_seconds` | `86400` | Grace after that interval before showing overdue. |
| `worker_stale_seconds` | `300` | Age of an active heartbeat before showing a warning. |

Scheduling belongs to cron/timers. A weekly patrol is not expected to emit a
heartbeat between runs. Freshness uses completed tracked invocations of the
configured scope; failed/interrupted or untracked reports do not make it current.

## Privacy, retention and verification

State directories must be canonical, private (0700), and accessible to the service
account. The adapter writes private files (0600) with atomic journal replacement.
Authenticated downloads enforce safe report lookup; process logs and arbitrary
filesystem paths are not exposed. Native reports can contain private labels,
checksums and stored file paths, so downloads remain administrator-only.

Back up the private directory while the patrol is idle. Reports, logs and journals
are retained until an operator agrees a retention policy; there is no automatic
deletion. Earlier experimental File Health SQLite state, if any, is neither
migrated nor deleted and is not used by the new journal.

From the source monorepo root:

```sh
bash scripts/check.sh
```

Tests use synthetic native subprocesses, including nonzero exits, missing reports,
signals and output limits. They check read-only journal/legacy-report behavior,
unsafe paths, authentication and escaping without bootstrapping an installation.
They do not establish installed SMTP delivery, native adapter behavior, or a
complete real patrol. Those remain operational validation steps.
