# Installation and acceptance

This runbook describes production-affecting operations. Execute them only after
explicit deployment authorization. Keep existing harvesting continuity until
routing, metadata and downstream reconciliation have been accepted.

## Prepare and verify

1. Verify the target Providence/PHP versions and run the source checks against
   that version. Keep Providence core unchanged. The plugin uses the supported
   application-plugin controller route and stock framework/catalogue APIs.
2. Compare the public field crosswalk with the installation's live schema and
   representative source/destination records. Review repeated identifiers,
   dates, creator roles, rights, Source and Relation. A richer schema cannot
   recover values absent from Providence; repairs are separate work.
3. Review the public Pawtucket and media URLs. Set `public_base_url` to the
   frontend, rather than the staff catalogue. Representation viewers use its
   supported `?representation_id=ID` object-page parameter. Original and preview
   URLs should use the public media alias. Where native URLs differ, configure
   both `media_source_url_prefix` and `media_public_url_prefix`, verify that the
   public alias serves the same files, and allow only final public media hosts.
4. Verify the frontend's intended publication policy. Keep
   `item_acl_policy=native` by default; select `public_flags` only where backend
   item ACLs govern staff scope separately from public access flags. Anonymous
   type/source and bundle checks still apply. Do not change stored ACLs or staff
   permissions to make an OAI record visible.
5. Choose the canonical OAI URL, public repository contact, permanent identifier
   namespace, earliest supported metadata datestamp, page size and token expiry.
   Historical dates describing the object are not OAI modification datestamps.
   Preserve already issued Providence identifiers when replacing a transport.
6. Agree on old-record replacement, withdrawals and reconciliation with the
   harvester. This provider advertises no tombstones and supplies no sets.

## Install the plugin

Keep target-specific source/configuration backups outside the web root. Copy
only `publicOai/` into Providence's `app/plugins/publicOai/`. Preserve existing
`conf/plugin.local.conf` and `conf/oai.local.conf` during updates; do not copy
examples over working local files.

For a new installation, create the local files from
`conf/plugin.local.conf.example` and `conf/oai.local.conf.example`. The metadata
configuration owns the audience, public URLs and repository name. The transport
configuration owns `oai_base_url`, `identifier_namespace`, `admin_email`,
`earliest_datestamp`, `records_per_page` and `token_ttl`.

Keep `enable=0` and `oai_enabled=0` while preparing the candidate. Both are needed
for publication. Shared source defaults remain disabled. These two private
configuration files are sufficient; the plugin does not require changes to
Providence's core OAI service or its `oai_provider.conf`.

The plugin route is:

```text
/service.php/publicOai/Repository/Index
```

Its controller uses the native service entry point and dispatcher. It responds
as XML without borrowing the signed-in user's publication rights. Do not route
anonymous harvesters through the staff `index.php` login flow.

For an installation that does not otherwise use the stock OAI provider, leave
that provider globally disabled in its ordinary local configuration. The plugin
transport has its own configuration and does not read native provider mappings.
Do not enable an obsolete native provider merely to retain its URL.

## Preserve an existing public endpoint

If `/service.php/OAI/objects` has already been shared, keep it as the configured
`oai_base_url`. Add an exact internal server/proxy alias from that path to
`/service.php/publicOai/Repository/Index`. Scope it to the intended virtual host
and endpoint; preserve other service routes.

The proxy/backend must receive the rewritten URI, including the plugin module,
controller and action, so `REQUEST_URI`/path-info parsing dispatches the plugin
route. Preserve the original query string, HTTP method and form-encoded POST
body. Do not turn this into a browser redirect, drop POST data, or broaden it to
all OAI/provider paths. The public canonical base URL in XML remains the shared
endpoint; internal routing and the metadata's public frontend links are distinct.

Validate the candidate server configuration and follow the target's normal
activation procedure only when authorized. Check both direct plugin routing
and the public alias anonymously. At the approved activation step, enable both
metadata publication and the plugin transport. Determine any OPcache action from
the actual runtime; cache purges or application restarts are not automatic steps.

## Optional native exporter mappings

The OAI provider does not need mapping imports. If staff also want native XML
exports, the two TSV mappings can use the existing `hookExportRecord` extension.
The native command is:

```sh
support/bin/caUtils load-export-mapping --file=/absolute/path/to/mapping.tsv
```

Run it as the normal application account only when that separate import is
intended. **It writes the database and replaces an exporter with the same code.**
Inspect/preserve existing definitions first. Never use it as a read-only test.
The optional mappings are `tadl_public_oai_mods` and `tadl_public_oai_dc`; they do
not add an OAI transport or enable publication.

## Acceptance

Validate `Identify`, `ListMetadataFormats`, `ListSets`, `ListIdentifiers`,
`ListRecords` and `GetRecord` with GET and form-encoded POST, including the public
alias. Verify the configured canonical base URL in responses. `ListSets` should
return `noSetHierarchy`. Validate each metadata format and OAI-PMH envelope against
its advertised schema, including error responses.

Compare anonymous and authenticated-staff results. Test public/private object
and representation combinations, denied bundles/ACLs, deleted records, absent
media, mixed-access objects and direct excluded-record requests. No private media
URL, caption, staff field or user information may appear. Confirm object/viewer,
original-file and preview links use the intended public frontend/media alias;
open representative links anonymously.

Test inclusive date bounds, `until` alone, resumed/repeated pages, expired and
mismatched tokens, duplicate/unknown arguments and a full harvest. Measure initial
page latency and a sparse incremental request before settling page size/cadence.
Reconcile complete IDs and duplicates across MODS and DC, comparing their headers
and datestamps. Verify related metadata/media changes are discoverable and test
withdrawal/republication against the downstream replacement procedure.

Give the harvester both tested format links, the shared base URL, identifier
policy, crosswalk, known source gaps and withdrawal procedure. A successful new
harvest alone does not prove that old or newly private records were removed.

## Upgrade and rollback

Preserve the plugin folder, its two local configurations and the exact server
alias during normal Providence upgrades. Re-run source and integration checks
against the new version. No patched core provider must be carried forward.

To stop publication, disable the plugin transport first. Restore only reviewed
plugin/server configuration changes, accounting for subsequent edits. Preserve
catalogue metadata, media and existing legacy harvesting continuity. If optional
native mappings were imported, do not restore an entire database to undo them.
