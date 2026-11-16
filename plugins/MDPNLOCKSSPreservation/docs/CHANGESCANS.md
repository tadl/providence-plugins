# Scheduled change scans

A change scan flags collections that may need another deposit, without reading
original-file contents or calculating their checksums. It saves its results and
UTC check time. The ordinary preservation report and each collection's
Preservation page read these saved results without repeating the scan.

## Running a scan

From the installed Providence application root:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-scan --user-id=7 --apply
```

Replace the example backend user ID with an active Providence administrator.
That account needs preservation permission and native ACL checks enabled. The
Unix service account must own the private preservation state directory. The
scanner reads the catalogue and writes only the plugin's private scan state;
it creates no preservation jobs, bags, transfers or confirmations. No MDPN
connection, profile refresh or SFTP credentials are required.

| Argument | Meaning |
| --- | --- |
| `--apply` | Required because the command saves scan results |
| `--user-id=N`, `-u=N` | Active Providence administrator |
| `--collection-id=N`, `-c=N` | Check one eligible archival root; omit to check all |
| `--format=text`, `--format=json`, `-f` | Summary with errors, or structured results; text is the default |
| `--quiet` | Suppress the native caUtils banner; use with JSON |
| `help` | Show the native command help |

The command returns nonzero for scan failures. Finding changed or undeposited
collections is a successful scan, not a command failure. Collections with active
deposits or held collection locks are skipped; their previous results remain.
Organizational units and subordinate collections are not independent scan rows.

## Scheduling

For a server whose system timezone is UTC, an example `/etc/cron.d/ca-mdpn-scan`
entry is:

```cron
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=""
0 7 * * * www-data umask 077; cd /var/www/ca && /usr/bin/flock -n /var/lib/providence-preservation/scan.lock /usr/bin/php support/bin/caUtils mdpn-preservation-scan --user-id=7 --apply --quiet >> /var/lib/providence-preservation/scan.log 2>&1
```

Adapt the application/state paths, Unix account and backend user ID. Install
the cron file as root, owned by root, mode `0644`, with a final newline. The
lock prevents overlapping scanner invocations. The private log records a short
dated completion summary and any collection errors. This example does not send
email or submit supplements automatically.

07:00 UTC is 2:00 a.m. Eastern Standard Time and 3:00 a.m. Eastern Daylight Time.
Review other scheduled I/O work when choosing the time. The plugin does not
install a schedule automatically.

## Reading the results

Run the ordinary report after a scan:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-report --user-id=7
```

`--with-history` omits collections with no deposit attempts. Leave it off to
also see new eligible collections needing an initial full deposit.

A confirmed deposit with no detected changes is `current`, **as of the saved
scan time**. A changed hierarchy is `out_of_date`. An unchanged uploaded deposit
still awaits LOCKSS confirmation. Failed deposit attempts and failed scans
remain visible separately from earlier successful preservation. The report
shows `LAST CHECKED (UTC)`; JSON identifies the method as
`change_log_and_file_metadata`. The Preservation page shows the same time,
result and change counts. A new deposit invalidates older comparisons until the
next scan. Changing confirmation alone does not invalidate a scan of that same
deposit. A scan failure replaces a previously clean result with a dated error.

Only the latest completed deposit and latest confirmed deposit need scan
inventories. Superseded temporary inventories are removed from the private
ledger on a successful scan; deposit history and recovery metadata remain.
No new ledger schema or Providence database migration is required.

## What is checked

The scanner follows native collection/object hierarchies, membership and
representation relationships. It uses scoped native change-log entries for
those records, labels, attributes, attachments, ACLs and direct relationships.
Logged rows are compared with saved metadata signatures, so a whole-row log
entry does not flag an unchanged record. The signatures cover metadata, not
the contents of original files.
It also checks definitions needed to interpret values currently in use.
Hierarchy/membership IDs are compared with the deposit inventory. Related
person/place descriptions, unused configuration and the archival root's sibling
sort order do not themselves trigger a supplement. Recognized generated preview
records and files are excluded.

For each original in the deposit, it checks the local regular file's existence,
readability, size, modification time and cached filesystem identity. It never
opens the original to read its contents. The first scan of a deposit initializes
a compact inventory from its recovery metadata and resolves original paths;
large older snapshots make this first initialization more expensive. Subsequent
scans reuse the small inventory and do not reload the full snapshot. Older
deposits use their capture time as a change-log boundary; new deposits record
the native log cursor to avoid ambiguity within a capture second.

## Limits and checksum verification

Change detection is not fixity verification. Matching file metadata cannot
prove identical bytes. For an older deposit's initial file observation, the
scanner checks the recorded size/name and whether the modification time is
later than capture; subsequent scans compare saved file observations.

Native change history must be available back to the deposit. Pruned or missing
history produces an error rather than a clean result. Direct SQL changes or
imports that deliberately disable native logging need separate verification.
Changes that cannot be matched to a saved metadata row are conservatively
flagged for review. No update is sent automatically.

Use the existing fixity patrol for routine checksum verification, or request a
full preservation comparison explicitly:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-report --user-id=7 --verify
```

That explicit report rereads originals and checks current metadata against the
saved snapshot. It remains read-only and does not overwrite scheduled scan
results or change LOCKSS confirmations.
