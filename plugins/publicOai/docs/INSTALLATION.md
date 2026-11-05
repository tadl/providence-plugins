# Installation and acceptance

This runbook describes production-affecting operations. Execute only after
explicit deployment authorization. Keep the current feed serving harvesters
until the new provider and downstream reconciliation have been accepted.

## Prepare and verify

1. Verify the target Providence/PHP versions and current source/configuration.
   Review the separately supplied native provider patch and run its isolated
   behavioral checker against the exact candidate. Preserve existing core
   changes; do not replace a whole upstream tree.
2. Review the public field crosswalk against the installation's live schema.
   Compare representative current source and destination records. Pay attention
   to repeated identifiers, dates, creator roles, rights, Source and Relation.
   A richer export cannot recover metadata absent from the destination.
3. Review the canonical frontend and media URLs, public media hosts, repository
   name, repository contact and new OAI identifier namespace. Use no internal
   hostnames in emitted links. Verify original and preview links anonymously.
   Set `public_base_url` to the public Pawtucket interface. Representation viewer
   links use its supported `?representation_id=ID` object-page parameter.
   Originals and previews should use the public frontend's media URL path too.
   If native Providence URLs have a different origin/path, configure the paired
   `media_source_url_prefix` and `media_public_url_prefix`. Verify that the public
   alias serves the same files before activation. Restrict allowed_media_hosts
   to final public hosts; source URLs are translated before that final check.
   Verify whether that public frontend enforces anonymous item ACLs. Keep
   `item_acl_policy=native` / `publicItemACLPolicy=native` by default; use matching
   `public_flags` values only for the explicitly reviewed publication policy
   where staff backend ACLs do not govern public display. Do not change stored
   ACLs or staff permissions to enable OAI.
4. Agree with the harvester on the replacement/reconciliation procedure for
   old identifiers and withdrawn records. This version has no tombstones and
   no OAI collection sets.

## Authorized installation

Keep a target-specific backup of the files/configuration being changed outside
the web root. Keep local configuration and catalogue/exporter definitions
private. Preserve the old harvesting route.

Copy only `publicOai/` to Providence's `app/plugins/publicOai/`. Preserve any
existing `conf/plugin.local.conf`. Create installation-local settings from
`conf/plugin.local.conf.example`; fill the reviewed public URLs and hosts. Keep
the plugin/provider disabled while installing the reviewed candidate.

Apply the separately verified native provider patch narrowly. Import each TSV
as an XML export mapping through Providence's mapping loader. The native command
is `support/bin/caUtils load-export-mapping --file=/absolute/path/to/mapping.tsv`;
run it as the normal application account. **Mapping import writes the database
and replaces an existing exporter with the same code.** Inspect/preserve existing
mapping definitions before importing; never use this command as a read-only test.

Prepare the installation's local `oai_provider.conf` override from
`conf/oai_provider.conf.example`. Merge with existing providers intentionally;
do not overwrite other provider configuration. Confirm the patch-required flags,
both mapping codes, public-only mode, required policy hook, no access bypass,
no sets and no deleted records. Enable the plugin and provider only at the
approved activation step. Determine any cache/OPcache action from the target
runtime; do not purge caches or restart a service as an automatic install step.

## Acceptance

Validate `Identify`, `ListMetadataFormats`, `ListSets`, `ListIdentifiers`,
`ListRecords`, and `GetRecord` with GET and form-encoded POST. `ListSets` should
return `noSetHierarchy`. Both metadata formats must validate against their
advertised schemas; protocol responses must validate against OAI-PMH 2.0.

Compare anonymous and authenticated-staff requests. Test public/private object
and representation combinations, denied bundles/ACLs, deleted records, missing
media, mixed-access multi-file objects, and direct excluded-record requests.
No private representation URL, caption or other metadata may appear.

Test inclusive `from` and `until` boundaries, `until` alone, repeated/resumed
pages, expired and mismatched tokens, invalid arguments, and a full harvest.
Reconcile complete ID counts and duplicates across MODS and Dublin Core; compare
each format's headers and datestamps. Verify metadata changes to related public
records/representations are discoverable. Test withdrawal and re-publication
against the agreed downstream replacement procedure.

Hand the harvester both tested URLs, the shared base URL, identifier policy,
metadata crosswalk, known source gaps and withdrawal procedure. A successful new
harvest alone is not proof that old or newly private records were removed.

## Rollback

Disable the new provider first. Restore only the reviewed source/configuration
changes, accounting for subsequent edits. Preserve catalogue metadata and media.
Do not restore an entire database to undo mapping installation. The legacy
harvesting route remains available until separately authorized retirement.
