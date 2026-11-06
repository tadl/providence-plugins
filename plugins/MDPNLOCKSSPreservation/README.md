# MDPNLOCKSSPreservation

MDPNLOCKSSPreservation creates preservation deposits from CollectiveAccess
Providence collections and transfers them to a LOCKSS staging service. It
packages uploaded original files together with collection, object and
representation metadata, relationships, and the configuration needed to
interpret that metadata. Staff manage deposits from the collection editor.

The plugin produces BagIt 1.0 packages using either the MDPN DART3 profile or
a generic profile for an agreed LOCKSS staging workflow. It performs the
packaging itself; DART does not need to be installed. Each tar archive is one
bag, intended for preservation as a separate LOCKSS Archival Unit (AU).

**Current status: pilot implementation.** The recovery tool reconstructs
original files and a complete catalogue snapshot. It does not yet import that
snapshot into a fresh Providence database. A validated import procedure and an
isolated restoration rehearsal are required before relying on the plugin to
restore a working catalogue. Institutional acceptance should also include
receiving-service processing and LOCKSS crawl verification. See the
[pilot acceptance checklist](docs/PILOT.md).

## Guide to this documentation

- [What a deposit contains](#what-a-deposit-contains)
- [Creating and confirming a deposit](#creating-and-confirming-a-deposit)
- [Full deposits, supplements and multiple parts](#full-deposits-supplements-and-multiple-parts)
- [Package names and identifiers](#package-names-and-identifiers)
- [Bag metadata and its sources](#bag-metadata-and-its-sources)
- [Recovery and continuing oversight](#recovery-and-continuing-oversight)
- [Installation and administration](#installation-and-administration)

## What a deposit contains

A deposit records the selected collection and its complete, non-deleted
hierarchy at the time of capture. A representation is Providence's file-bearing
record linked to an object; an object can have multiple representations, and a
representation can be shared. The deposit includes:

- The collection and its descendant collections.
- Objects belonging to those collections through the configured hierarchy
  relationship, including object components.
- The uploaded original files attached to those objects, including shared
  representations, captions, sidecars and independently uploaded attachments.
- File and media attributes and their uploaded attachments.
- Current descriptive and administrative metadata, multilingual labels,
  repeated values, metadata containers, relationship types and attributes,
  ordering, primary-representation flags, and access-control settings.
- Directly related authority records, such as entities and places, and the
  ancestor records needed to retain their hierarchy.
- Vocabulary, metadata and relationship definitions, together with a
  Providence installation profile and source identifiers for interpretation
  and subsequent restoration.

Public access and catalogue status settings do not exclude material from a
deposit. Restricted originals and confidential administrative or donor metadata
are included. Permission to create deposits therefore grants broader export
capability than access to a public catalogue or a particular editor screen.
Native record-level permissions still apply: if the requesting user cannot
read a required record, capture stops rather than silently omitting it.

A relationship to a record outside the selected collection hierarchy is
recorded as a boundary reference. It does not cause the entire neighbouring
collection to be exported. If collection-hierarchy mode is disabled and no
hierarchy relationship type is configured, all object-to-collection relationship
types are treated as membership.

Providence-generated thumbnails, viewer tiles, document-page previews and
video frames are excluded from the file payload. Their descriptive records may
remain in the snapshot with instructions identifying the source from which they
can be regenerated. Uploaded JPEGs and other independently supplied files are
retained. Administrators should review sites with custom media-processing or
multifile import procedures; the filename suffix `original` alone does not
establish that a file was uploaded rather than generated.

The export represents the current state, not every change made before the first
deposit. Previously deleted material can only be recovered if an earlier deposit
or another backup contains it. User accounts and passwords, server credentials,
application code and the server environment are outside the preservation set.
Normal database, media, application and configuration backups remain necessary.

### Files inside a bag

| Location | Purpose |
| --- | --- |
| `bag-info.txt` | Transfer metadata identifying the package and its responsible organization and contact. |
| `manifest-sha256.txt` | SHA-256 checksums for every payload file. |
| `tagmanifest-sha256.txt` | Checksums for the BagIt declaration, bag metadata and payload manifest. |
| `data/originals/` | Uploaded originals carried by this particular bag. |
| `data/metadata/snapshot.json` | Authoritative catalogue snapshot, file inventory, change list and recovery chain. |
| `data/metadata/collections.csv`, `objects.csv`, `representations.csv` | Record exports for inspection and subsequent processing. |
| `data/metadata/relationships.csv`, `files.csv`, `changes.csv` | Relationship, file-inventory and change exports. |
| `data/metadata/metadata-schema.json`, `installation-profile.xml` | Definitions and configuration needed to interpret the exported metadata. |
| `data/README.txt` | Package and recovery instructions. |

CSV cells contain JSON values so repeated values, language information, nulls
and structured metadata remain distinguishable. They are not flat display
exports or ready-made Providence import templates. The reference definitions
can include vocabulary and configuration entries unused by this particular
collection. The JSON and XML provide complementary restoration information.
See the [recovery format](docs/RECOVERY.md) for the detailed structure.

## Creating and confirming a deposit

Only the top-level archival collection in each branch can be preserved. Its
complete hierarchy is included in the deposit; subordinate collections, series
and folders have no separate Preservation menu item or report row. Organizational
units are grouping records and cannot themselves be deposited.

For catalogues organized beneath institutions, branches or cooperatives,
configure the [organizational-unit marker](#organizational-units-and-preservation-roots).
The first unmarked collection below marked organizational ancestors is eligible,
at any hierarchy depth. Without a configured marker field, native hierarchy
roots are eligible. These restrictions also apply to administrators, direct
requests and CLI jobs, and are checked again by the background worker.

An administrator must first install the plugin, initialize its private history
store and configure processing and delivery. Authorized staff can then use the
following workflow.

1. Open the collection editor and select **PRESERVATION**, after **LOG** in the
   left menu. Confirm that the selected collection and its hierarchy are the
   intended preservation scope.
2. Review the organization, contact name, contact email and both descriptions.
   Contact fields use the signed-in Providence user's name and email unless
   installation-specific contact defaults are configured. Both remain editable;
   missing account details must be completed before sending.
   **External-Description** starts with the first paragraph of the collection's
   **Description**, or its title when no description is available.
   **Internal-Sender-Description** contains the collection title, complete
   **Description**, **Scope and Content**, and **Provenance** when available.
   Both are editable. Review the short summary for an external audience and
   retain the complete narrative for future custodians.
3. Choose **Create new full deposit**, or **Supplement the latest complete
   deposit** when an eligible deposit already exists for this destination.
4. Select **Create and send preservation deposit**. Processing continues in the
   background after the browser closes. With local test delivery configured,
   the button instead creates a local package without uploading it.
5. Refresh the page to review the result. For a multipart deposit, verify that
   every part was delivered. Expand **View bag-info.txt** beside any bag to
   inspect or copy its exact transfer metadata from a read-only textarea.
   Retain access to the recovery roadmap, which lists the bags and checksums
   needed to reconstruct this version.
6. Coordinate with the receiving service to check processing and arrange LOCKSS
   crawls of every new AU. A successful staging upload is not evidence of a
   completed crawl or distributed preservation.
7. After checking the node evidence, an authorized operator records a
   **Confirmation reference**. Include the AU identifiers, nodes checked,
   successful crawl dates and a report reference or evidence URL. The plugin
   records the reference, operator and recording time; it does not query nodes
   or independently verify that evidence. Confirm a parent deposit before its
   supplement.

Both description fields accept paragraphs. On submission, formatting is removed
and paragraph breaks become spaces, so each occupies one physical line in
`bag-info.txt`. Neither field has an arbitrary text-length limit. The complete
internal narrative includes the first paragraph as well as all later description
paragraphs. Identical repeated narrative values are included once; missing fields
are skipped. The original rich metadata remains intact in the catalogue snapshot.

Bag-info sections are collapsed by default and appear after packaging completes,
including for a completed package awaiting a transfer retry. They contain the
text written into that particular bag, rather than a preview or a reconstruction
from current settings. Older jobs created before this display was added have no
saved display copy; their original bag-info.txt remains inside the archive.

### Status and changes since the last deposit

| Status | Meaning |
| --- | --- |
| `queued` / `running` | Capture, packaging, validation or transfer is pending or in progress. |
| `scanned` | A comparison was completed; no bag was created. |
| `unchanged` | A requested supplement found no changes; no bag was created or uploaded and the completed baseline is unchanged. |
| `built` | Local archives were created and validated; they have not been submitted to a preservation service. |
| `uploaded` | Every part reached staging; LOCKSS preservation has not been confirmed. |
| `confirmed` | An authorized operator recorded preservation evidence and a reference. |
| `failed` | The operation did not complete; it cannot establish a supplemental baseline. |

**Scan for changes without creating bags** compares current metadata and
original-file SHA-256 values with the latest complete deposit. Changes to
hierarchy, membership, relationships, descriptive or administrative metadata,
and original files can affect the result. Scans read every original and may
take time for large collections. Change detection runs when a scan or deposit
is requested; it does not run automatically on every catalogue save. A previous
scan describes its capture time, not subsequent edits.

Preservation freshness follows the collection hierarchy, objects,
representations and attachments, their direct relationships, and the uploaded
originals. Changes to those records or files require attention. A confirmed
deposit that still matches is `current`: preservation succeeded and the
collection content has not changed.

Editor layouts, validation settings, packaging policy and unused definitions do
not make the collection stale. Neither do descriptive edits to a related person
or place, such as adding subject headings to that person's record. Those records
remain in the full recovery snapshot and are updated in the next deposit, but
do not by themselves justify a supplement. Generated hierarchy indexes and view
counters are also excluded from freshness checks.

Definitions are significant only when needed to interpret values actually used
by the collection's own records or direct relationships. Renaming an assigned
subject term, changing a used relationship's meaning or changing a used metadata
field's code/datatype can therefore require attention. Adding unused terms,
relationship types or field applicability rules cannot. The complete raw schema
is still saved for recovery; it is not the collection's preservation status.

The page shows the most recent 100 jobs and the first 200 change entries for a
job. The complete history remains in the private ledger, and the bags retain
complete change lists. A deposit's roadmap retains older recovery dependencies
and shows the number of supplements since the latest full deposit. Only one
operation per collection can run at a time.

## Full deposits, supplements and multiple parts

A **full deposit** contains the complete selected state and all its uploaded
original files. **Create new full deposit** starts an independent recovery
chain, even if the collection has been deposited before.

A **supplement** is an incremental deposit. It contains a complete current
metadata snapshot and file inventory, but transfers only new or changed
original-file bytes. Unchanged files refer to their earlier bags. Metadata-only
changes can produce a supplement without new original files. Removed records,
relationships and files are recorded in the change list; they are absent from
the latest reconstructed state but remain available in earlier immutable bags.
An unchanged collection cannot produce an empty redundant supplement. Instead,
the attempt completes as `unchanged`, without sending anything or advancing the
successful baseline. Older empty-supplement attempts that were recorded with the
specific no-changes error display the same outcome; their stored history is not
rewritten. Actual packaging and transfer failures remain failures.

Relevant metadata changes can produce a supplement containing no new original
bytes. Background recovery-context changes are captured with the next such
deposit or a requested new full deposit. No configuration warning or separate
deposit is required just because installation settings changed.

A **part** is a physical subdivision of one deposit, not a separate revision.
Every part repeats the complete metadata snapshot and inventory. A multipart
deposit is complete only when all its parts have been delivered. Each tar is a
separate BagIt bag and a separate LOCKSS AU.

For example, a full deposit may be followed by an incremental deposit large
enough to require two parts:

```text
EXAMPLE_C12_001.tar           Full deposit
EXAMPLE_C12_002_P001.tar      Incremental deposit, part 1 of 2
EXAMPLE_C12_002_P002.tar      Incremental deposit, part 2 of 2
```

Recovering the state after deposit 002 requires all three archives. The recovery
tool reads the baseline and both incremental parts using the recorded inventory
and chain; it does not determine dependencies from the filenames alone.

MDPN recommends approximately 50 GB per AU. This is not an inherent LOCKSS size
limit. The plugin defaults to a maximum physical tar size of 50,000,000,000
bytes, including metadata and tar overhead, and partitions deposits to fit
that configured size. An administrator can agree a larger size with the receiver.
A single original file is never fragmented: if it cannot fit, packaging stops
until an adequate size is configured. See the administrator settings below.

The first deposit to a new destination must be full. Local test packages cannot
serve as the baseline for an SFTP destination. Destination identity includes
transport, destination ID, host, port and directory. Changing those settings
requires a full deposit for the new destination.

## Package names and identifiers

The naming pattern is:

```text
ORGANIZATION_C<collection-ID>_<deposit-revision>[_P<part-number>].tar
```

| Component | Meaning |
| --- | --- |
| `EXAMPLE` | Organization code configured for the installation. |
| `C12` | Providence collection database ID, rather than its accession number or editable identifier (`idno`). |
| `001` | Deposit revision, assigned when the job is created. Failed attempts can leave gaps; scans do not consume revisions. |
| `P001` | Part number, present only when that deposit has multiple parts. |

Revision and part numbers are padded to at least three digits. A single bag
has no part suffix. `External-Identifier` is the archive filename without
`.tar`. Long collection titles and changes to titles or accession numbers do
not change this pattern. Separate catalogues depositing under the same
organization must coordinate organization codes so package identifiers remain
unique.

The plugin also assigns a permanent installation identifier and a random
identifier to each deposit. These distinguish source records and preservation
events in the ledger and snapshot; the random deposit ID is not a content hash
and does not appear in the filename. The existing revision counter supplies
the public deposit number. Retain production history so published identifiers
and revision numbers are not reused. History resets are for disposable pilot
runs only.

## Bag metadata and its sources

New MDPN bags use the following DART3 profile order, including empty optional
fields. No plugin-specific recovery fields are added to `bag-info.txt`; deposit
identity, revision, mode, parent and recovery dependencies are in the separately
checksummed `data/metadata/snapshot.json`.

| Field | Source or meaning |
| --- | --- |
| `Source-Organization` | Organization value submitted in the form, prefilled from local configuration and checked against the MDPN profile. |
| `Bag-Count` | Physical part number and total for this deposit, such as `1 of 1` or `2 of 2`; not the number of deposits in the collection's history. |
| `Bag-Group-Identifier` | Blank for MDPN deposits. Its empty line is retained to preserve DART3 field order. |
| `Bag-Size` | Payload size formatted using DART3's 1024-based calculation and labels such as `kB` and `MB`; not the physical tar size. |
| `Bagging-Date` | UTC packaging date and time in DART3's RFC 3339 format. |
| `Bagging-Software` | This plugin's release identifier. The plugin, rather than an installed DART application, creates the bag. |
| `Contact-Email` | Email submitted by the operator; initially the configured contact email, or the signed-in Providence user's email when no override is set. |
| `Contact-Name` | Contact name submitted by the operator; initially the configured contact name, or the signed-in Providence user's first and last name when no override is set. |
| `Contact-Phone` | Blank; not collected by the current form. |
| `External-Description` | The short summary reviewed in the form; initially the first Description paragraph, or the title if unavailable. Serialized as one line of plain text. |
| `External-Identifier` | Package filename without `.tar`; used as the AU title by MDPN's published processor. |
| `Internal-Sender-Description` | The complete narrative reviewed in the form: title, full Description, Scope and Content, and Provenance when available. Serialized as one line of plain text. |
| `Internal-Sender-Identifier` | Permanent repository identifier, Providence table name and collection database ID. |
| `Organization-Address` | Blank; not collected by the current form. |
| `Payload-Oxum` | Payload byte count and file count, separated by a period. Counts include metadata files as well as originals. It is a completeness check, not a substitute for SHA-256 validation. |
| `BagIt-Profile-Identifier` | Identifier supplied by the MDPN DART profile. |

`External-Identifier` is line 11. Optional blank values are not removed, so an
empty description or group identifier does not shift its position. This also
supports MDPN's published manifest-heading implementation. In that processor,
a blank group identifier causes `journalTitle` to default to the AU's external
identifier: filename prefixes do not automatically group AUs in LOCKSS.
Generic-profile bags retain a collection-group value.

The full title and description provide descriptive context; the compact
filename provides identification. The snapshot separately records the catalogue
capture time, full native metadata, relationships, definitions and source IDs.

## Recovery and continuing oversight

Use a deposit's recovery roadmap to identify the independent full deposit and
all subsequent supplements required for the desired state. Retrieve every part
of every listed deposit. A later independent full deposit needs only its own
parts. Retain the retrieved archives unchanged.

The standalone recovery tool validates manifests and checksums, checks the
recorded chain, and reconstructs the current original-file set and catalogue
snapshot without a running Providence installation or its private ledger.
It verifies each recovered original against its recorded size and SHA-256.
The [recovery procedure](docs/RECOVERY.md) explains the commands, file format,
missing-part failures and requirements for a future native Providence import.
Successful file recovery does not establish that a working catalogue has been
restored.

Regular review should distinguish **delivery**, **confirmed preservation** and
**whether the deposited state is still current**. The read-only maintenance
report lists eligible archival collection roots, including those whose
preservation is out of date. Organizational units and subordinate records are
excluded from individual rows; changes anywhere in a preserved hierarchy affect
its root's freshness check.
A full check reads current metadata and original-file hashes; a history-only
check does not establish present-day freshness. See
[maintenance reporting](docs/REPORTING.md) for status definitions and administrator
commands.

## Installation and administration

The following sections are for the system administrator responsible for the
Providence server, private preservation storage, processing schedule and SFTP
connection. Installing the plugin does not initiate a deposit or transfer.
The shared configuration defaults to disabled, generic packaging and local
delivery. It supplies no institution identity or receiving-service credentials.

### Requirements

- Providence 2.0.10 extension APIs. Recheck compatibility when upgrading.
- A 64-bit Linux/macOS host, PHP 8.2+ CLI and web runtimes, PDO SQLite, JSON, DOM,
  hash and the normal Providence dependencies. `proc_open` must be available.
- Python 3.9+ with its standard library; no pip packages are required.
- MDPN mode additionally requires PHP cURL in both web and CLI runtimes, a
  working system TLS certificate store and outbound HTTPS to
  `raw.githubusercontent.com`. Generic mode requires no MDPN network access.
- Local readable originals for every scoped representation and attached file.
  Network-mounted media is acceptable when mounted as normal readable files.
  External URLs, missing originals and derivative substitutes stop the job.
- A local filesystem supporting SQLite locking and `flock`. Do not put the
  preservation ledger on NFS or share it across multiple application servers.
- A private directory outside both the web root and the source repository,
  owned by the common web/CLI queue account (`www-data` in the server examples)
  with permissions `0700`.
- Sufficient space for retained archives and new staging. Budget up to twice
  the original file set plus repeated metadata for each active build. The
  worker stages originals while determining which files an incremental deposit needs.
- The native Providence task queue, processed by the CLI service account.
  Jobs continue after the browser closes.

SFTP additionally needs Providence's phpseclib installation, an approved sender
IP where required, an independently verified server host-key fingerprint, a
private credentials file and an agreed upload/finalization convention.

### Choose a receiving service and profile

The plugin name does not restrict it to MDPN or TADL. Keep the installed
directory and `mdpn-preservation-*` command names unchanged for either profile.
Configure your own institution identity and choose the profile required by your
receiving service:

| Profile | Intended use and requirements |
| --- | --- |
| `generic` (default) | A receiver that agrees to accept this plugin's BagIt 1.0 tar packages, SHA-256 manifests and metadata layout. Any institution name is accepted. This does not configure a LOCKSS network, an ingest service or AU definitions. |
| `mdpn` | An MDPN deposit using organization names and packaging rules fetched from the [canonical MDPN settings](https://raw.githubusercontent.com/MiDPN/MDPN-Config/refs/heads/main/MDPN-DART-Settings.json). Select your own institution's exact approved name; confirm it and the endpoint with MDPN. |

`organization_code` is your institution's compact package-name prefix in either
mode: two to twelve uppercase letters or digits, without spaces or punctuation.
It is separate from the full `source_organization` name. Coordinate prefixes
when separate catalogues send packages to the same receiving service.

Start from the [generic local example](conf/plugin.local.conf.example). For an
MDPN installation, change the following values as part of that configuration:

```ini
bagit_profile = mdpn
organization_code = EXAMPLE
source_organization = REPLACE_WITH_YOUR_APPROVED_MDPN_MEMBER_NAME
```

Replace both illustrative identity values. TADL's name and organization code
are not defaults for other MDPN members. An unlisted organization name is
rejected in MDPN mode; ask the coordinator to update the canonical settings.

`bagit_profile` controls packaging rules. `destination_id` is a local name for
tracking delivery history, not a provider selector. Host, directory, credentials
and part size must be agreed and configured separately. A receiver that requires
a different BagIt version, checksum algorithm, mandatory tag set, authentication
method or transfer API needs an implementation change and compatibility testing.

When updating an existing MDPN installation, keep `bagit_profile = mdpn` and its
institution identity explicit in `plugin.local.conf`; preserve that file during
source updates. The new shared defaults do not select MDPN on the site's behalf.

### Install for local testing

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
4. Choose `generic` or `mdpn` as described above, and replace the example
   organization code and name with your own institution's values. Leave contact
   defaults blank to request them on each form, or set your own defaults.
   Verify the state path, Python interpreter and service account on this server.
   Keep the receiver's settings separate from the institution identity.
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

   In MDPN mode this also downloads and validates the canonical packaging
   settings. Resolve any network or compatibility error before creating a job.

7. Grant `can_preserve_collections` to the intended backend role. Grant
   `can_confirm_preservation` as well to people who will verify node ingestion.
   Reload the role editor after clearing the cache; sign back in if needed.
   Providence administrators already pass these native action checks. For an
   administrator-only pilot, leave both actions ungranted to other roles.
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

   The internal queue handler is `MDPNPreservation`, fitting Providence's
   20-character handler column. The installed plugin directory remains
   `MDPNLOCKSSPreservation`; no core database schema change is required.

Initialization creates a SQLite ledger and a permanent random installation
identifier. It does not modify Providence's database schema. Each catalogue
database needs a separate state directory. This initial version assumes one
catalogue/database context per installation; multisite hostname routing needs a
separate integration review.

Do not start a copied development checkout against production connection
settings. Source tests below never bootstrap Providence or read `setup.php`.

### Adapting to your Providence catalogue

The plugin reads native metadata definitions and exports the scoped records'
complete metadata, rather than relying on TADL's editor layout or a fixed CSV
field mapping. Review collection membership using your installation's
`ca_objects_x_collections_hierarchy_enabled` and
`ca_objects_x_collections_hierarchy_relationship_type` settings. These belong
to Providence's application configuration, not `plugin.local.conf`. If hierarchy
mode is enabled, a valid membership relationship type must be configured. If
no type is configured and hierarchy mode is disabled, all object-to-collection
relationship types count as membership. Verify scope on a small test collection.

The external summary uses the first paragraph of element `description`, falling
back to the native collection title. HTML paragraph boundaries and blank lines
in plain text determine that first paragraph; wrapped lines within a paragraph
are joined. The complete internal narrative starts with the title and includes
`description`, `collection_scope_content` and `provenance` when present and
applicable. A site using different element codes can enter both descriptions in
the form; absent autofill fields do not exclude metadata from the snapshot.
CLI submissions can supply `--description` and `--internal-description` explicitly.
Custom media-processing and multifile import procedures also need the review
described under [permission and media-processing details](#permission-and-media-processing-details).

### Configuration reference

Put installation-specific settings in `conf/plugin.local.conf`. Settings omitted
there inherit `conf/plugin.conf`; preserve the local file during updates. The
[example local configuration](conf/plugin.local.conf.example) contains synthetic
values for review by the administrator. These files use Providence's native
configuration syntax: `#` comments and unquoted scalar values, including names
containing spaces. Keep SFTP usernames and passwords in the separate JSON
credentials file.
The marker setting is quoted to keep its brackets literal.

| Setting | Purpose |
| --- | --- |
| `enable` | Defaults to `0`; set to `1` after reviewing the local configuration. |
| `state_directory` | Canonical absolute path to private local storage, outside the web root and source repository. |
| `python_binary` | Absolute path to the Python 3 interpreter used by the worker. |
| `organization_code` | Required local identity: two to twelve uppercase letters or numbers used at the start of package names. No shared default. Keep it stable after production deposits begin. |
| `source_organization` | Default for the required organization field in the form. No shared default; must exactly match the MDPN profile when that profile is selected. |
| `contact_name`, `contact_email` | Optional overrides for the required contact fields in the form. Each blank setting uses the signed-in user's corresponding account details. CLI submissions still need configured defaults or explicit contact arguments. |
| `bagit_profile` | Defaults to `generic` for an agreed compatible service; explicitly select `mdpn` for MDPN's profile and organization list. |
| `part_size_bytes` | Maximum physical tar size in bytes; defaults to `50000000000`. Use a positive whole number without units or commas. |
| `transport` | `local` to create packages without transfer; `sftp` to deliver them to staging. |
| `destination_id` | Stable local name, using 1–64 letters, digits, underscores or hyphens; defaults to `local-testing`. Separate from collection and bag identifiers. |
| `sftp_host`, `sftp_port`, `sftp_directory` | Receiver DNS hostname or IPv4 address, port (default `22`) and writable path as seen by the SFTP account. IPv6 literals are not supported. |
| `sftp_host_key_sha256` | Independently verified OpenSSH `SHA256:` fingerprint without trailing `=` padding; not a known_hosts public-key line. |
| `credentials_file` | Path to the separate private JSON file containing the SFTP username and password. |
| `organizational_unit_element` | Text metadata element code containing organizational markers, without `ca_collections.`. Blank by default: use native hierarchy roots. Configure `internal_notes` to use the example marker there. |
| `organizational_unit_marker` | Exact, case-sensitive standalone line identifying an organizational unit; defaults to `[[organizational_unit]]`. Keep the configuration value quoted. |

Invalid part-size settings fail before collection capture or original-file
staging. A blank part-size override does not mean the default: omit the setting
to inherit it. Changing the transport, destination ID, host, port or directory
creates a new destination identity and requires a full deposit.

### Organizational units and preservation roots

An organizational unit groups archival collections by institution, department,
branch or cooperative. It is not itself a preservation deposit. Enable the
marker using installation-local configuration:

```ini
organizational_unit_element = internal_notes
organizational_unit_marker = "[[organizational_unit]]"
```

Append `[[organizational_unit]]` on its own line to each organizational record's
notes, leaving existing notes intact. Mark every organizational ancestor, even
when several are nested. The marker must be saved on that record itself; it is
never inherited. Plain text and HTML paragraphs are supported, while mentioning
the token in ordinary prose does not activate it. A configured field must exist
and have the Text datatype. A different field code or marker can be configured
for another institution.

```text
Example cooperative             marked organizational unit
  Example library               marked organizational unit
    Historical photographs      eligible preservation root
      Series 1                  included with Historical photographs
        Folder 1                included with Historical photographs
```

In this mode, unparented archival records outside the marked organizational
hierarchy are ineligible and produce report warnings. Add new organizational
units by marking their records; no database IDs or organization names are
hardcoded in the plugin. Leaving the field setting blank instead selects native
hierarchy roots, for catalogues without organizational grouping records.

The Preservation menu appears only on eligible roots. A direct URL for an
ineligible record remains a read-only history page, with a link to its eligible
archival parent where one exists. Existing deposits, download/roadmap access and
confirmation records are retained. New deposits, scans and transfer retries are
blocked for ineligible records, including roots moved after a job was queued.
Fresh capture is checked again before its result can advance the baseline.

### Application cache after installation or updates

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

### Enable SFTP after the local pilot

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
Only username/password authentication is implemented; SSH private-key and agent
authentication are not configuration options in this version.

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

### Permission and media-processing details

The `can_preserve_collections` role action authorizes export of all collection
metadata and uploaded originals. `can_confirm_preservation` separately permits
recording preservation evidence. Native collection and member-record ACLs must
allow reading. Viewing earlier exports remains subject to the current collection
ACL and preservation permission.

The worker reloads the requesting backend user and checks permission again.
It establishes that user's native context before loading records. Because the
general Providence task processor disables item-level ACLs, preservation runs
in an isolated PHP process with ACL checks enabled. No Providence core patch or
other local plugin is required.

Generated multifile previews are recognized by the native JPEG output,
positional page/frame paths, source media type and generated or empty preview
filename. Their snapshot records include `generated_preview`, `source_file_key`
and `disposition: regenerate`. Capture stops if the source original is absent.
Named uploads, non-positional attachments and unrecognized multifiles remain
preserved. Custom multifile importers require a local review of these rules.

### Canonical MDPN settings

MDPN mode reads the named MDPN profile in
[MDPN-DART-Settings.json](https://raw.githubusercontent.com/MiDPN/MDPN-Config/refs/heads/main/MDPN-DART-Settings.json).
Organization names, tag ordering, required fields, supported defaults and value
restrictions, the profile identifier and packaging constraints come from that
source. DART accounts, storage services, contact overrides and other operator
values are not imported. The plugin contains no bundled live MDPN profile;
synthetic test fixtures are used only by the test suites.

Initialization, new scan/deposit submissions and fresh queued captures download
and validate the current settings. A capture pins one imported policy for its
entire operation. The validated cache is
`state_directory/mdpn-settings-cache.json`, outside the application, mode `0600`.
The cache records its source and refresh time. The normalized policy and a
SHA-256 fingerprint of the imported policy are saved in each bag's catalogue
snapshot, so later upstream edits do not change an existing deposit.

Reports read the cache without network access or writes. Retained-package
retries and offline recovery use the original archives and recorded policy,
without fetching current settings. To refresh before a report, or after updating
an installation that previously used a bundled profile, run from Providence:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-refresh-profile --apply
```

This refreshes settings only; it creates no preservation job or bag. Clearing
Providence's application cache does not refresh MDPN settings. Generic mode
does not download or cache MDPN settings.

New work requires a successful download: an outage or invalid response leaves
the previous cache intact but does not silently reuse stale policy for a new
capture. HTTPS verification is mandatory, redirects are not followed, and the
download has a 5-second connection limit, a 15-second total limit and a 1 MiB
size limit. Unsupported upstream requirements stop new work with an error;
changes such as a different checksum algorithm or required grouping convention
may still need a plugin update. Updates that move `External-Identifier` away
from line 11 also require a compatibility review of MDPN's receiving workflow.

The saved snapshot's integrity fingerprint covers the recorded packaging policy.
Freshness checks compare catalogue content separately, so policy changes alone
do not mark a collection out of date, report scan changes or justify an empty
supplement. Existing bags and their fingerprints remain unchanged. Review a
changed receiving policy with MDPN and create a new full deposit when adopting
a changed format.

### Other LOCKSS deployments

Set the organization code/contact defaults, a stable destination ID, verified
SFTP endpoint and private credentials. Select `bagit_profile = generic` when
the destination does not use MDPN's organization allowlist.

Generic bags carry the configured organization/contact values, external package
identifier, stable internal source identifier, collection group, part count,
bagging date/software and payload oxum, plus both descriptions when supplied.
They do not claim MDPN's profile URI, DART3 field order or MDPN's optional blank
fields and size tag. The generic group value is the snapshot's permanent
collection-group identifier. Review this actual output with the receiver before
using it as a production format. A full first deposit is required at each new
destination; changing profiles alone does not create a new destination identity.
Agree any profile change with the receiver and create a new full deposit when
adopting the changed format.

The receiving workflow must accept BagIt 1.0, UTF-8 tags, SHA-256 payload and tag
manifests, uncompressed tar serialization and this package layout. It must be
configured to ingest and expose those packages as its own LOCKSS AUs. SFTP
deposits are sent to a staging workflow, not directly to a
LOCKSS node. Arbitrary custom BagIt profiles, other checksum algorithms, S3 and
provider-specific ingest APIs are not implemented.
Agree a part-size limit with that receiver; 50 GB is the supplied starting
value, not a universal LOCKSS requirement. Arrange staging processing, AU
creation, crawls and preservation confirmation with its operators. The plugin
does not discover those services or poll LOCKSS nodes for acceptance.

### DART3 metadata compatibility

New MDPN bags follow DART3's output for the supplied MDPN profile: preserve the
profile's field order, retain empty optional fields, then append the automatic
`BagIt-Profile-Identifier`. `External-Identifier` is therefore line 11 even when
the description is blank. `bag-info.txt` contains exactly that DART/MDPN field
set; plugin-specific recovery information stays in the separately checksummed
`data/metadata/snapshot.json`. Generic-profile bags also keep recovery information
in the snapshot.

Like DART3, `Bag-Size` describes payload bytes scaled by 1024, with one decimal
place and labels such as `kB` and `MB`; it is not the physical tar size.
`Bagging-Date` is the UTC packaging timestamp in RFC 3339 format. The catalogue
capture timestamp remains in the snapshot. `Bagging-Software` truthfully names
this plugin. Stable collection IDs, unique bag IDs and the documented deposit
chain remain part of the preservation format. Existing archives stay immutable
and remain readable by the recovery tool.

The profile identifier is copied from MDPN's DART settings, which currently
retain the legacy APTrust `btr-v0.1.json` URL. It is an identifier, not a runtime
download target or a substitute for the actual profile saved in the snapshot.
The canonical MDPN settings file is fetched instead. The worker selects the
supported BagIt 1.0 declaration, even if the DART tag's legacy default is 0.97;
the upstream profile must explicitly accept 1.0.

Reference behavior was checked against DART3's `dart-runner` v1.0.6:
[tag ordering and blank fields](https://github.com/APTrust/dart-runner/blob/v1.0.6/core/bagit_profile.go#L421-L430),
[automatic metadata](https://github.com/APTrust/dart-runner/blob/v1.0.6/core/bagger.go#L422-L431)
and [size formatting](https://github.com/APTrust/dart-runner/blob/v1.0.6/util/util.go#L442-L451).
`tests/fixtures/dart3-mdpn.json` records this external compatibility contract;
the worker suite checks emitted archives, the receiver's line-11 title lookup,
empty descriptions, multipart/supplemental recovery and legacy bag recovery.

### Processing logs

The site's scheduler determines where queue output is written; configure a
private log and rotation policy. Individual job diagnostics are in
`state_directory/jobs/JOB_ID/`, including `php-worker-output.txt`,
`php-worker-error.txt`, `worker-result.json` and `worker-error.txt`. Treat those
files as private operational records. Review them when a job fails before
resetting or retrying it.

### Operations and development

For a read-only report across eligible collection roots, including outdated preservation:

```sh
sudo -u www-data -- php support/bin/caUtils mdpn-preservation-report --user-id=7
```

Use an authorized backend administrator ID. This checks fresh metadata and
original SHA-256 across their complete hierarchies by default, and separates
uploaded content from confirmed preservation. `--history-only` skips current
content checks; `--format=json --quiet` provides machine-readable output;
`--fail-on-attention` gives a nonzero exit when action is needed. It creates no
jobs, bags or ledger writes. See [maintenance reporting](docs/REPORTING.md) for
status meanings, privacy, organizational hierarchy, runtime cost and exit behavior.

Job directories are stored under `state_directory/jobs/JOB_ID/`. The ledger,
queue log and locks stay at the state root. Existing jobs directly under the
state root remain readable and retryable in place; do not move them manually,
because their saved requests contain absolute paths. Read-only reports support
both layouts. Job directories and the `jobs/` parent must remain private (0700).

Back up the **entire private state directory**, including `ledger.sqlite`, `jobs/`,
any legacy job directories, `result.json` and request/diagnostic files, while no
jobs are running. Metadata and originals there are private collection exports.
Retained tar files accumulate until an operator reviews retention. Do not delete ledger
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

For a foreground pilot using an approved test collection:

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
php plugins/MDPNLOCKSSPreservation/tests/eligibility_test.php
php plugins/MDPNLOCKSSPreservation/tests/profile_test.php
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
