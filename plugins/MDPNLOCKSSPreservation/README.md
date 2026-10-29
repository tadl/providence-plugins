# MDPNLOCKSSPreservation

Preserve complete Providence collection hierarchies as BagIt 1.0 tar deposits,
track full and supplemental deposits, and reconstruct their current catalogue
snapshot and original files without a running Providence installation.

This is the initial **pilot implementation**. Its standalone synthetic tests
exercise collection scope, PHP-to-Python packaging, multipart deposits,
supplements, recovery, permissions, CSRF and SFTP protocol behavior. A real
Providence/database/media pilot and an actual MDPN transfer have not yet been
performed. Do the [pilot acceptance checks](docs/PILOT.md) before relying on it.

The shared configuration defaults to disabled and local packaging. Source
installation never initiates a transfer, creates catalogue records, changes
native access settings or starts a service.

## Requirements

- Providence 2.0.10 extension APIs. Recheck compatibility when upgrading.
- A 64-bit Linux/macOS host, PHP 8.2+ CLI and web runtimes, PDO SQLite, JSON, DOM,
  hash and the normal Providence dependencies. `proc_open` must be available.
- Python 3.9+ with its standard library; no pip packages are required.
- Local readable originals for every scoped representation and attached file.
  Network-mounted media is acceptable when mounted as normal readable files.
  External URLs, missing originals and derivative substitutes stop the job.
- A local filesystem supporting SQLite locking and `flock`. Do not put the
  preservation ledger on NFS or share it across multiple application servers.
- A private directory outside both the web root and the source repository,
  owned by the common web/CLI queue account (`www-data` in the server examples)
  with permissions `0700`.
- Sufficient space for retained archives and new staging. Budget up to twice
  the original file set plus repeated metadata for each active build. This
  initial worker stages originals even when testing an incremental comparison.
- The native Providence task queue, processed by the CLI service account.
  Jobs continue after the browser closes.

SFTP additionally needs Providence's phpseclib installation, an approved sender
IP where required, an independently verified server host-key fingerprint, a
private credentials file and an agreed upload/finalization convention.

## Scope and permissions

Public `access` and catalogue `status` values never filter inclusion. A deposit
includes the selected non-deleted collection and its descendants; objects
attached using the configured collection-hierarchy relationship; their object
components; all original representations; captions, sidecars and multifile
originals; and file/media attributes, including their multifiles and authority
lookups. If collection-hierarchy mode is disabled
and no hierarchy relationship type is configured, all object/collection
relationship types count as membership.

Shared objects and representations are included once, with all applicable
relationships. Capture retains native rows, labels in every locale, repeated
attributes, relationship attributes, ranks, primary flags and ACL rows. Directly
related authorities and their ancestor records are included as metadata. All
native relationships touching scoped collections/objects/representations are
recorded. References to other objects/collections outside this hierarchy are
explicit boundary references, not instructions to traverse another collection.

The `can_preserve_collections` role action is an explicit grant to export **all
metadata fields**, including private donor/administrative fields and originals.
It is stronger than access to a particular editor screen or public download.
Grant it only to authorized preservation staff. Native collection and member
record ACLs must still allow reading: an inaccessible member stops the entire
job rather than creating a partial deposit. Viewing historical exports is
controlled by the current collection ACL and the preservation role action.

The worker reloads the requesting backend user and checks permission again.
Providence's general `process-task-queue` command disables ACLs globally, so this
plugin delegates to a fresh PHP process that explicitly enables native ACL
checks. No core patch or dependency on another local plugin is needed.

Regenerable thumbnails/previews, user accounts/passwords, server configuration,
application code, databases outside the scoped catalogue export and previously
deleted records without an earlier deposit are outside the preservation set.
This plugin complements normal database/media/application backups.

## Install for local testing

Run installed-server CLI commands from the Providence root using
`sudo -u www-data --` as shown below. This runs each command as the web/queue
account, so cache files, ledger files and archives have consistent ownership.
If an installation uses another service account, substitute it consistently.
The `--user-id` option selects a Providence backend user for authorization; it
is separate from the Unix account selected by sudo.

1. Copy only this plugin directory to
   `app/plugins/MDPNLOCKSSPreservation/`, preserving its exact name.
   Deny direct HTTP access to that filesystem path. The supplied `.htaccess`
   does this on Apache when overrides are enabled; configure an equivalent deny
   location on nginx. The interface uses normal Providence front-controller
   routes and needs no direct access to plugin files.
2. Create the private state directory with the ownership and permissions above.
3. Copy `conf/plugin.local.conf.example` to `conf/plugin.local.conf` and review
   every value, including `enable = 1`. That real local file is ignored by Git
   and must be preserved during updates. Keep `transport = local` for the first
   pilot.
4. For MDPN, use `bagit_profile = mdpn`, `organization_code = TADL` and the exact
   `source_organization = Traverse Area District Library`. Set the real contact
   defaults locally. The synthetic example uses the generic profile instead.
5. Clear Providence's application cache after copying and enabling the plugin,
   before granting roles or starting preservation jobs. Follow the
   [cache maintenance guidance](#application-cache-after-installation-or-updates)
   below and run from the installed Providence root:

   ```sh
   sudo -u www-data -- php support/bin/caUtils clear-caches --cache=app
   ```

6. Initialize the private ledger:

   ```sh
   sudo -u www-data -- php support/bin/caUtils mdpn-preservation-init --apply
   ```

7. Grant `can_preserve_collections` to the intended backend role. Grant
   `can_confirm_preservation` as well to people who will verify node ingestion.
   Reload the role editor after clearing the cache; sign back in if needed.
8. Configure a preservation queue runner under `www-data`. For an explicit
   preservation-only pilot invocation:

   ```sh
   sudo -u www-data -- php support/bin/caUtils mdpn-preservation-process-queue --apply
   ```

   Schedule this command through the server's normal cron or service manager
   so jobs continue after the browser closes; avoid overlapping invocations.
   It processes only this plugin's native queue handler, rechecks each
   requester's permissions in an isolated process and runs no recurring tasks.
   Providence 2.0.10's general `process-task-queue --tasks=...` can stop at an
   unrelated task ahead of preservation jobs; use the dedicated command when
   processing preservation independently. An existing general queue runner may
   also process preservation tasks normally.

Initialization creates a SQLite ledger and a permanent random installation
identifier. It does not modify Providence's database schema. Each catalogue
database needs a separate state directory. This initial version assumes one
catalogue/database context per installation; multisite hostname routing needs a
separate integration review.

Do not start a copied development checkout against production connection
settings. Source tests below never bootstrap Providence or read `setup.php`.

## Application cache after installation or updates

Providence caches plugin role-action definitions and processed configuration.
Clear the application cache once after installing/enabling this plugin and
after updates that add or change role actions or configuration. Repeat this
step when troubleshooting missing preservation actions, a missing inspector
link or stale settings after checking the installed files and local `enable`
value:

```sh
sudo -u www-data -- php support/bin/caUtils clear-caches --cache=app
```

Run from the installed Providence root during a planned maintenance window.
Application cache clearing includes user sessions and temporary application/task
queue files. Let active uploads, imports and queue work finish, pause the queue
runner for this step, and expect users to need to sign in again. Check the
command's output and exit status; a cache directory that is not writable by
`www-data` needs its ownership/permissions corrected before retrying.

`--cache=app` selects the application caches; omitting it defaults to `all`,
which also clears the temporary user-media upload area. Use the native command
instead of deleting cache directories by hand. The private preservation state
directory, ledger, retained bags and credentials must stay outside these cache
locations.

After clearing, reload the role editor and collection inspector, then resume
the queue runner. Routine reports, scans, deposits and confirmations need no
cache clear. The plugin never purges Providence caches automatically.

This command clears Providence's application caches, not PHP OPcache. If a PHP
code update remains stale because of the server's OPcache settings, follow the
installation's normal PHP-FPM/Apache reload procedure; a CLI `opcache_reset()`
does not reset the web runtime's OPcache.

## Archivist workflow

Open an existing collection and follow **Preservation and deposit history** in
its inspector. Review the organization/contact values and optional description.
Choose a full deposit or a supplement to the last complete deposit for the
current destination, then submit. The operation is queued; refreshing the page
shows its result and downloadable parts.

**Scan for changes** compares a fresh complete metadata/file-fixity snapshot
against the latest complete deposit without creating bags or uploading files.
It reads every original, so allow time for large collections. Change detection
currently runs on explicit scans and deposits, rather than every catalogue save
or an automatically installed schedule. The page displays the capture time;
it does not assert that a previous scan describes subsequent edits.

History currently shows the latest 100 jobs. The complete ledger, bag snapshots
and each deposit's cumulative recovery roadmap retain older dependencies. The
page shows the number of supplements required since the last full deposit and
links to the exact bags/checksums needed for recovery. Change lists on the page
show the first 200 entries; the bags contain the full lists.

States have distinct meanings:

| State | Meaning |
| --- | --- |
| queued / running | Pending or active capture, packaging, validation or transfer |
| scanned | Comparison only; no bag was created |
| built | Verified local test archives; no preservation-service submission |
| uploaded | Every part delivered to staging; node preservation is unconfirmed |
| confirmed | An authorized person recorded node/crawl evidence and a reference |
| failed / cancelled | Incomplete operation; never a supplemental baseline |

One active operation per collection is allowed. There is no automatic source
file deletion, old-deposit overwrite, record edit or archive-retention cleanup.

## Full deposits, supplements and identifiers

Collection identity uses the native `collection_id`, qualified by the permanent
installation identifier. Duplicate/changing `idno` values do not affect it.
The group identifier is stored on first use, so organization-code edits do not
rename an existing group. Preserve the ledger with the catalogue when moving
servers. A distinct catalogue needs a new ledger/installation identifier.

Every preservation run reserves a revision and a globally distinct random
deposit ID. Failed revisions remain in history and are never reused. Scans do
not consume revisions. Each physical bag has a unique `External-Identifier`.

`Bag-Group-Identifier` groups the collection across its lifetime. By this
plugin's documented convention, **`Bag-Count` counts parts of one deposit**, not
all historical deposits: `1 of 1` for an ordinary full/supplemental deposit,
`1 of 3` through `3 of 3` for one multipart deposit. Additional
`Preservation-Deposit-Identifier`, `Preservation-Parent-Deposit`,
`Preservation-Mode` and `Preservation-Revision` tags make this unambiguous.
Recovery uses the explicit deposit chain, not an inference from Bag-Count.

Parts are independent valid bags, each repeating the complete metadata and
inventory. The target is 50,000,000,000 **physical archive bytes**, including
metadata/tar overhead. Single originals are never cut into byte fragments. If
one file cannot fit, the build stops; agree a larger limit with the repository
before changing `part_size_bytes`.

Supplements carry complete current metadata, the complete current file inventory
and a change list, but only new/changed original bytes. Unchanged entries name
their exact earlier bag and path. Removed records/files/relationships disappear
from the current snapshot and appear in the change list; earlier bags remain
immutable. An unchanged collection cannot create an empty redundant supplement.
**Create new full deposit** starts a self-contained recovery chain instead.

A local-test chain can never become the baseline for an SFTP chain. Destination
identity includes transport, configured destination ID, host, port and directory.
Changing those values requires a new full deposit for the new destination.

## Enable SFTP after the local pilot

MDPN currently uses an IP-restricted staging service. Arrange its sender-IP
allowance, confirm the destination path and obtain its SSH host-key fingerprint
through a trusted channel. Do not discover-and-trust the key automatically.

Agree that `<bag>.tar.partial` files are ignored by preprocessing and that
renaming a complete upload to `<bag>.tar` is the publication step. The account
must have one writer and allow temporary-file reads, stat, upload/resume and
rename. Completed uploads are read back and compared byte for byte. A matching
existing completed archive is accepted as an idempotent retry; a different
archive is never overwritten. A matching partial prefix can resume.

Use an absolute `sftp_directory`, `/` for the account's server root, or `.` for
its login/home directory (the equivalent of DART's empty SFTP bucket setting).

Store the real credentials in a `0600` file **outside the web root and Git
checkout**, readable by the service account. `conf/credentials.json.example`
documents its two keys. Point the ignored local configuration at that file:

```ini
transport = sftp
destination_id = example-lockss
sftp_host = ingest.example.org
sftp_port = 22
sftp_directory = /incoming
sftp_host_key_sha256 = SHA256:REPLACE_WITH_INDEPENDENTLY_VERIFIED_FINGERPRINT
credentials_file = /etc/providence/preservation-credentials.json
```

The placeholder fingerprint intentionally fails validation. Actual endpoint and
credential values belong only in installation-local files. Credentials are not
written to bags, subprocess arguments, worker requests or the ledger. Keep the
credential file separate from routine plugin source updates and preservation
state backups.

The credentials file uses JSON so punctuation in generated passwords is
unambiguous. Parse errors produce a generic message, never a configuration
parser dump of password text. The ignored `plugin.local.conf` stores its path.

**Retry transfer of retained package** reuses the same verified archives and
identifiers, including already completed parts. Capture/validation failures
require a new job. If the ingest service has already consumed some parts,
reconcile that state before retrying; duplicate-submission policy is a property
of the receiving service. A partial multipart submission never advances the
collection's successful baseline.

MDPN supplies no automated preservation receipt. After arranging crawls of the
new AUs and checking node evidence, an authorized operator can record a manual
confirmation reference. A supplement can only be confirmed after its parent.
Neither a successful upload nor local packaging claims LOCKSS replication.

## Other LOCKSS deployments

Set the organization code/contact defaults, a stable destination ID, verified
SFTP endpoint and private credentials. Select `bagit_profile = generic` when
the destination does not use MDPN's organization allowlist.

The receiving workflow must accept BagIt 1.0, UTF-8 tags, SHA-256 payload and tag
manifests, uncompressed tar serialization, additional preservation tags, and
this package layout. It must know how to expose/ingest those packages into its
own LOCKSS AUs. SFTP deposits are sent to a staging workflow, not directly to a
LOCKSS node. Arbitrary custom BagIt profiles, other checksum algorithms, S3 and
provider-specific ingest APIs are not implemented.

`profiles/mdpn.json` records the required fields and organization allowlist from
the supplied MDPN DART settings. It explicitly selects BagIt 1.0, correcting the
settings export's conflicting 0.97 version-tag default. Treat MDPN policy changes
as reviewed source/configuration changes; the worker never downloads profiles
at runtime. Each snapshot records the actual profile requirements used.

## Offline recovery and native CA reimport

See [Recovery format and procedure](docs/RECOVERY.md). The standalone command
verifies bags and reconstructs the selected deposit's current file set and full
catalogue snapshot, without CA, its database or the SQLite ledger. It does not
perform database writes or pretend that raw rows can be inserted into a fresh
Providence database unchanged. A native CA importer and its ID crosswalk need
validation against an isolated fresh installation during the pilot.

## Operations and development

For a read-only report across all collections, including outdated preservation:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-report --user-id=7
```

Use an authorized backend administrator ID. This checks fresh metadata and
original SHA-256 by default, recognizes parent-deposit coverage, and separates
uploaded content from confirmed preservation. `--history-only` skips current
content checks; `--format=json --quiet` provides machine-readable output;
`--fail-on-attention` gives a nonzero exit when action is needed. It creates no
jobs, bags or ledger writes. See [maintenance reporting](docs/REPORTING.md) for
status meanings, privacy, inherited coverage, runtime cost and exit behavior.

Back up the **entire private state directory**, including `ledger.sqlite`, job
directories, `result.json` and request/diagnostic files, while no jobs are
running. Metadata and originals there are private collection exports. Retained
tar files accumulate until an operator reviews retention. Do not delete ledger
or result files when reclaiming archive space. Credentials and normal complete
application/database/media backups have separate backup requirements.

If a process/machine stops, inspect native queue/process state first. An
administrator can mark an abandoned job failed when no worker holds its lock:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-reset --job=JOB_ID --apply
```

The old queued task will become a no-op; no catalogue records or archives are
deleted. Retry a retained completed package or create a new deposit. Normal
completion does not require resetting jobs or restarting services.

For an explicitly reviewed foreground pilot:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-create --collection-id=12 --user-id=7 --mode=full --apply
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-run --job=JOB_ID --apply
```

The IDs above are illustrative; choose an authorized test collection/account.
Do not pass `--disable-item-level-access-control` to preservation commands.

Development checks run as your normal developer account from the source
monorepo root (including on macOS), rather than the deployed server account:

```sh
bash scripts/check.sh
```

Or run the focused suites:

```sh
php plugins/MDPNLOCKSSPreservation/tests/core_test.php
php plugins/MDPNLOCKSSPreservation/tests/service_test.php
php plugins/MDPNLOCKSSPreservation/tests/report_test.php
php plugins/MDPNLOCKSSPreservation/tests/sftp_test.php
python3 -B -m unittest discover -s plugins/MDPNLOCKSSPreservation/tests -p 'test_*.py'
```

Tests use synthetic data and temporary private directories. The SFTP test uses
an in-memory protocol fake, not a server. Source syntax/standalone checks do not
verify real MySQL isolation, native media adapters, installation-profile import,
queue bootstrap, large production collection scale, or provider acceptance.
Packaging uses Python's standard library instead of DART/Runner, so no GUI,
Runner binary or additional PHP dependency is installed on the server.
