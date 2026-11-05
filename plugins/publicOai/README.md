# Public OAI metadata

A plugin-owned OAI-PMH provider for public MODS 3.8 and simple Dublin Core
metadata. One object remains one OAI item; both formats use the same eligibility
policy, header identifier and datestamp. The provider, catalogue adapter and
metadata renderer live in this plugin. Providence core remains unmodified.

Developed against Providence 2.0.10 and PHP 8.3+. The native service dispatcher
loads the plugin controller at `/service.php/publicOai/Repository/Index`.
Providence's stock OAI provider is separate and can remain disabled; this plugin
does not use its protocol implementation, configuration or identifier helper.

Both metadata export and the OAI transport default to disabled. Installing or
pushing source does not enable a feed. The plugin does not change catalogue
records, access flags or representation files.

## Publication contract

An object must be non-deleted, public (`access=1`), and have at least one
non-deleted, public, usable representation. Anonymous type/source permissions
and metadata/media bundle access are checked separately. A staff login never
enlarges the exported audience.

The default `item_acl_policy=native` additionally enforces native anonymous item
ACLs. Some installations deliberately use Providence item ACLs to segment staff
editing, while their separate Pawtucket configuration publishes by public access
flags. Select `public_flags` only after verifying that publication policy.
Neither mode changes stored ACLs or backend staff permissions. Private objects
and representations remain excluded in both modes.

Only approved descriptive fields and readable related records are exported.
Objects with only private representations are excluded. Mixed-access objects
export their public representations only. Private paired JPEG/PDF copies remain
private; a representation does not qualify solely because a database row exists.
See [the crosswalk](docs/CROSSWALK.md) for the field allowlist and fidelity limits.

The public Pawtucket frontend supplies object, representation-viewer and related
record links. Original-file and thumbnail links use its public media URLs too.
When Providence and Pawtucket expose shared media under different origins or
paths, configure the paired `media_source_url_prefix` and
`media_public_url_prefix`. The adapter translates only that exact source path,
preserves the hash/filename suffix and validates the resulting public host.
The final host allowlist should contain only intended public media hosts;
unmatched backend URLs then fail publication checks. Files and native
media-volume settings are unchanged. File paths, staff notes and preservation
state are not exported.

## Configuration and endpoint

Copy the two examples to installation-local files:

- `conf/plugin.local.conf`: metadata publication, audience policy, frontend and
  media URLs; set `enable=1` only at authorized activation.
- `conf/oai.local.conf`: transport settings; set `oai_enabled=1` only at
  authorized activation. Configure `oai_base_url`, `identifier_namespace`,
  `admin_email`, `earliest_datestamp`, `records_per_page` and `token_ttl`.

Both switches must be enabled for the provider to disseminate records. Preserve
these local files during plugin updates; never commit them. The shared source
files `plugin.conf` and `oai.conf` stay disabled.

For a new installation, the canonical `oai_base_url` can be the plugin route.
Example harvest links are:

- `https://collections.example.org/service.php/publicOai/Repository/Index?verb=ListRecords&metadataPrefix=mods`
- `https://collections.example.org/service.php/publicOai/Repository/Index?verb=ListRecords&metadataPrefix=oai_dc`

An installation can retain an already shared URL such as
`/service.php/OAI/objects` with an exact internal server/proxy alias to the plugin
route. Preserve the HTTP method, POST body and original query arguments, and
forward the rewritten URI so the backend dispatches the plugin controller.
Configure `oai_base_url` to that stable public endpoint; it is independent of
the internal route and the frontend URLs contained in metadata. No HTTP redirect
or core-controller replacement is required. See [installation](docs/INSTALLATION.md).

`ListMetadataFormats` advertises both prefixes. Basic Dublin Core provides the
required OAI-PMH format; MODS preserves more roles, date types, authority
information and related-item structure. The OAI provider renders metadata
directly and does not require native exporter mappings or mapping imports.
The included TSV mappings are optional for staff-initiated native exports.

## Identifiers and harvest behavior

Choose an organization-controlled, permanent identifier namespace for a new
repository. Headers use `oai:<namespace>:<Providence object ID>`; catalogue and
legacy identifiers remain separate metadata values. Never reuse an Omeka
namespace with unrelated Providence numeric IDs. Once Providence identifiers
have been issued, retain the namespace when changing the provider's transport
or internal route. Switching metadata format does not create a new OAI item.

All six OAI-PMH verbs are handled. Collection sets are unsupported;
`ListSets` returns `noSetHierarchy`. Collection context is present in metadata.
The provider advertises `deletedRecord=no`: an object that becomes private or
loses its last public usable representation stops appearing. Agree a full
replacement/reconciliation workflow with the harvester before cutover;
incremental harvesting alone cannot remove previously harvested withdrawals.

Date bounds are inclusive UTC values. Pagination freezes candidate IDs and
rechecks current publication on every page. Opaque cache-backed tokens are bound
to the configuration, format and verb, with an explicit expiry; the default
lifetime is one day. Metadata and related-media changes contribute to record
datestamps. Mapping/configuration changes require a replacement harvest unless
a deliberate metadata-revision policy accounts for them.

## Checks and upgrades

From the repository root:

```sh
bash scripts/check.sh
```

Synthetic suites exercise the plugin's real provider, argument parsing,
publication decisions and metadata serialization, including offline OAI-PMH,
MODS and DC schema validation. No installation setup or database is loaded.
Live routing, media delivery, complete harvesting and downstream reconciliation
remain separate acceptance checks.

Upgrade Providence normally while preserving the custom plugin directory and
its local configuration, as for the other application plugins. Recheck service
routing, native catalogue APIs, the XML formatter and anonymous publication
against the new version. No local core patch needs to be reapplied. Retain the
server alias and configured canonical base URL when preserving a shared endpoint.
