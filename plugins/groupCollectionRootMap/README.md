# groupCollectionRootMap

Scope Providence collections and objects to a user's assigned collection roots,
including descendants. Entities, places, occurrences/events and other authority
records remain global. Core files are unchanged.

Source lives in `plugins/groupCollectionRootMap/` in
[tadl/providence-plugins](https://github.com/tadl/providence-plugins). Install this
folder as `app/plugins/groupCollectionRootMap/`, preserving its name and local
configuration. Shared defaults disable both the plugin and native ACL mode.

For the record permissions panel, start with
[Reading the collection scope permissions](docs/COLLECTION_SCOPE_GROUPS.md).
It explains the generated groups, “Everyone: no access” and the inheritance
controls in plain language.

## Scope rules

- `user_root_collection_map` maps backend user IDs to root collection IDs. Roots
  themselves and every descendant are included.
- Objects belong to their related collections' trees. The configured
  `ca_objects_x_collections_hierarchy_relationship_type` selects relationships;
  when blank, all object/collection relationships count.
- Objects shared with an assigned tree stay visible even when also linked to
  other trees. In native ACL mode, object children also belong to their object
  ancestors' trees. Child memberships do not expand their parents' access.
- Objects without qualifying membership are hidden from restricted users.
- `required_role` optionally limits which users are restricted. With
  `require_mapping_for_all_users = 1`, matching users without a mapping see no
  collections or objects. Other backend users remain unrestricted.
- Administrators retain Providence's native bypass. Native role, bundle and
  type permissions still apply to every operation.

## Modes and configuration

Copy `conf/plugin.local.conf.example` to `conf/plugin.local.conf` on the
installation. Local values override shared defaults. Keep real user IDs and
mappings out of Git, and exclude local overrides from deployment copies.

| Setting | Meaning |
| --- | --- |
| `enable` | Enable this plugin |
| `native_acl_segmentation` | Opt in to the native ACL rollout below; default `0` |
| `required_role` | Role name, code or ID; blank applies to all backend users |
| `bypass_for_administrators` | Must remain `1` in native ACL mode |
| `require_mapping_for_all_users` | Restrict matching users who lack a mapping |
| `allow_root_parent_assignment` | Permit top-level collection placement |
| `user_root_collection_map` | User ID to allowed root collection ID list |

Synthetic example:

```conf
required_role = cataloguer
require_mapping_for_all_users = 1
user_root_collection_map = {
    12 = [101],
    34 = [202, 203]
}
```

With `native_acl_segmentation = 0`, existing behavior remains: restricted
hierarchy bundles, collection autocomplete, mixed object/collection hierarchy
lookups, direct editor guards and collection-parent validation. **Legacy mode
does not filter general search or browse results.** It writes no ACLs or groups.

With `native_acl_segmentation = 1` and the required app settings, the plugin
maintains native record ACLs for **only collections and objects**. Providence's
ACL-aware searches, browses, lookups, related-record access and direct record
checks use those grants. Authorities opt out of record ACLs. Public `access`
fields and media files are not modified.

## Review and activate native ACLs

This is a permissions migration, separate from installing source. Test it on an
isolated staging installation before scheduling production work. Commands below
run from a configured Providence installation, not this repository. The plugin
must be enabled for its caUtils command to be registered.

1. Install source while preserving `conf/plugin.local.conf`. Keep native ACL mode
   off while preparing mappings. Preview the projection and required settings:

   ```sh
   php support/bin/caUtils sync-collection-scope-acl --dry-run
   php support/bin/caUtils sync-collection-scope-acl --config
   ```

   Omitting both `--dry-run` and `--apply` also previews. Output contains counts
   and configuration/conflict messages without record or user details. `--config`
   prints settings required by the installed datamodel; it writes nothing.
   Incomplete configuration is expected during preparation.

2. Resolve existing ACL conflicts deliberately. Apply refuses positive unmanaged
   world grants, any unmanaged user/group ACL (including denies), reserved group
   code collisions, public enrollment in managed groups, or application roles on
   managed groups. It never silently replaces manual permissions. Missing/deleted
   mapped roots and hierarchy cycles also stop the operation.

3. In a maintenance window, stop other catalogue writers and take a normal
   database/configuration backup. Create a private backup directory **outside
   the served application tree**, with mode `0700`. Apply also journals existing
   object/collection ACLs, managed groups/memberships and changed inheritance flags
   there in a mode `0600` JSON file before any changes. This contains private IDs;
   keep it outside Git and the webroot. There is no automatic restore command.

4. Set `native_acl_segmentation = 1` in plugin.local.conf and add the printed
   settings to Providence's server-local app configuration. They enable native
   item ACLs, set default item access to `0`, enable ACLs for objects/collections,
   and opt every other ACL-capable table out. Keep administrator bypass enabled.
   Do not use caUtils's `--disable-item-level-access-control` option. Review a
   fresh preview, then apply (replace the placeholder backup directory):

   ```sh
   php support/bin/caUtils sync-collection-scope-acl --apply --backup-dir /private/acl-backups
   php support/bin/caUtils sync-collection-scope-acl --dry-run
   ```

   Apply rechecks under a database advisory lock and commits ACLs, memberships
   and inheritance flags in one transaction. A second preview should report zero
   changes/conflicts. Non-administrator UI requests are blocked until synchronized.

5. Verify restricted users for each root, a multiple-root user, an unmapped
   required-role user, an unrestricted user and an administrator. Check search,
   browse/facets, autocomplete, direct URLs, related objects on shared authority
   pages, saved-result navigation, creation (including collection siblings),
   moves and relationship removal. Shared objects appear in either assigned tree;
   authority records stay global. Measure request latency and memory too.

Do not enable native ACLs on Pawtucket as part of this rollout. If its settings
inherit from Providence, review them separately before changing anything; public
site access policy is a separate decision. Back up both app and plugin settings.
Turning the plugin off alone does not undo the managed ACL migration.

## Ongoing synchronization and boundaries

The plugin owns role-less private groups `gcrm_all` and `gcrm_r_<root ID>`, their
memberships, and ACLs tagged `gcrm:v1` (within the native ten-character notes
limit). Unrestricted backend users join `gcrm_all`; restricted users join only
their assigned root groups.
Grants permit read/edit/delete at the record layer; native application roles
still decide which operations a user may perform. Do not edit managed grants or
add application roles to these groups. Unused root groups can remain empty.

Native object/collection ACL inheritance flags are disabled by the projection
to avoid competing grants. The plugin calculates tree membership itself.
Manual ACLs are preserved unless conflicting and requiring operator resolution.
The exception is the native direct creator grant for a new record inserted by
the current request for a backend user: it is replaced inside the insert
transaction with managed group grants. The normal editor's pending collection
context grants access while it finishes attaching the collection.

Normal Providence UI writes reconcile permissions after dispatch, including
relationship removals. Non-administrator read requests audit the projection
before dispatch and block stale/conflicting state. Mapping changes, role changes,
bulk imports, API writers, maintenance scripts and direct SQL changes require an
explicit preview/apply before restricted use resumes. Services and out-of-band
writers do not all pass through the UI guard. Reconcile after those operations;
this is not an independent tenant security boundary for integrations that bypass
native ACLs, or protection for static media URLs.

The conservative guard scans hierarchy IDs, relevant ACLs and backend users on
each non-administrator UI request. Staging performance checks with representative
catalogue size are necessary before rollout. Sync is also proportional to
catalogue size; stop other writers during large imports and initial migration.

After ACL changes, search/browse cache namespaces are invalidated. Saved object
and collection result lists are invalidated per session on a changed projection,
while query text and authority result contexts are preserved. Sessions are never
globally flushed.

Runtime registration uses native AppController plugins. An idempotent
`hookAddDomainSecurityPolicy` callback handles early plugin initialization,
returning existing domains unchanged. Core classes, CSP domains and search
controllers are not patched.

## Development checks and compatibility

From the monorepo root, run `bash scripts/check.sh`, or run
`php tests/acl_test.php` from this plugin folder. PHP with PDO SQLite and a
**trusted Providence 2.0.10 reference** are required. The workspace's reference
is detected automatically; standalone clones can set
`PROVIDENCE_REFERENCE_ROOT=/path/to/trusted/providence`.

The synthetic SQLite suite exercises projection, migration/idempotence,
conflicts, private backups, concurrency rejection, creation, relationship
removal, cache invalidation and legacy opt-out. It executes Providence's actual
`ca_acl::accessForRow` and `BaseFindEngine::filterHitsByACL` methods without
bootstrapping an installation or reading its credentials, and enforces the notes
length from the native SQL schema. This is not a live
MySQL/HTTP integration test. Recheck hooks, ACL behavior and the staging cases
above after Providence upgrades. Production activation is a separate step.
