# Public OAI metadata

Public MODS 3.8 and simple Dublin Core exports for the native Providence OAI-PMH
provider. One object remains one OAI item; both formats use the same eligibility
policy and header identifier. This plugin does not implement a second OAI server.

The plugin defaults to disabled. Source installation and source-control pushes
do not enable a feed. No catalogue records, access flags or representation files
are changed by the plugin.

## Publication contract

An object must be non-deleted, public (`access=1`), and have at least one
non-deleted, public, usable representation. Anonymous type/source permissions
and metadata/media bundle access are checked separately. A staff login never
enlarges the exported audience.

The default `item_acl_policy=native` additionally enforces native anonymous item
ACLs. Some installations deliberately use Providence item ACLs only to segment
staff editing, while their separate Pawtucket configuration publishes by public
access flags. For that verified public policy, explicitly select `public_flags`
in both plugin and provider configuration. The service rejects mismatched
policies. Neither mode changes stored ACLs or backend staff permissions. Private
objects and representations remain excluded in both modes.

Only approved descriptive fields and anonymously readable related records are
exported. Public objects with only private representations are excluded. A
private object with a public representation is excluded. Mixed-access objects
export their public representations only. Private paired JPEG/PDF copies remain
private; a stored representation does not qualify solely because it exists.

Media URLs must use explicitly allowed HTTPS hosts. The configured public
Pawtucket frontend supplies object, representation-viewer and related-record
landing URLs; direct original/thumbnail URLs have separate technical purposes.
File paths, internal
notes, preservation state and unrestricted attribute dumps are not exported.
See [the field crosswalk](docs/CROSSWALK.md) for mappings and fidelity limits.

## Native provider requirement

Developed against Providence 2.0.10 and PHP 8.3+. The unmodified 2.0.10 provider
does not provide the complete publication contract. The separately maintained
workspace `patches/providence/oai-public-provider.patch` and its isolated checker
must be reviewed and verified against the target before activation. They are
outside this source repository. Preserve them with the CollectiveAccess
workspace; a standalone clone alone is not an installation package.

The patch adds an opt-in public-object mode and a native `hookOAIRecordInfo`
extension. This plugin supplies the final metadata eligibility and dependency
datestamp through that hook. The native exporter's existing `hookExportRecord`
receives an access-checked metadata tree; its XML formatter handles serialization.
The two small TSV mapping definitions never traverse record metadata themselves.

## Formats and identifiers

The native provider key is `objects`. These are example harvesting links, to be
replaced with the installation's public Providence hostname:

- `https://collections.example.org/service.php/OAI/objects?verb=ListRecords&metadataPrefix=mods`
- `https://collections.example.org/service.php/OAI/objects?verb=ListRecords&metadataPrefix=oai_dc`

The format prefixes select mappings `tadl_public_oai_mods` and
`tadl_public_oai_dc`. `ListMetadataFormats` advertises both. Basic Dublin Core
remains available as required by OAI-PMH; the richer MODS description preserves
roles, date types, authority distinctions and related-item structure.

Use a new installation-specific OAI namespace with stable CA object IDs. Never
reuse a previous repository's namespace while substituting unrelated numeric
IDs. The metadata carries catalogue and legacy identifiers separately from the
OAI header identifier. Switching format does not create a new OAI item.

Collection sets are intentionally unsupported initially. Collection context is
part of metadata. The first version advertises `deletedRecord=no`: it does not
maintain a publication ledger or durable tombstones. An object that becomes
private or loses its last public usable representation stops appearing. Agree
an explicit full-replacement reconciliation workflow with the harvester before
cutover; incremental harvesting alone cannot reliably remove those old records.

## Checks

From the repository root:

```sh
bash scripts/check.sh
```

The public OAI suites use synthetic catalogue records, permission decisions and
URLs. They exercise metadata serialization through the trusted native XML
formatter and validate both schemas offline. No installation setup or database
is loaded. The shared CI reference checkout includes the required native files.
The separate native provider patch checker must also pass before installation.

See [installation and acceptance](docs/INSTALLATION.md). Live schema/mapping
validation, a complete harvest, and downstream replacement remain explicit
acceptance steps; synthetic tests do not establish those results.
