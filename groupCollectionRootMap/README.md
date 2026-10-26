# groupCollectionRootMap plugin

Providence application plugin that scopes collection hierarchy access based on user role and user ID mapping.

## What it does

- Optionally gates checks by required role (for example, `cataloguer`).
- Maps user IDs to allowed root collection IDs.
- Expands each mapped root into its full descendant subtree.
- Validates collection parent assignment before insert and update.
- Rewrites hierarchy bundle lookup endpoints for both collection and object editors to subtree-filtered plugin endpoints.
- Filters collection autocomplete, hierarchy level loading, hierarchy ancestor loading and hierarchy sort operations to the allowed subtree.
- Filters mixed object+collection hierarchy browsing when `ca_objects_x_collections_hierarchy_enabled` is enabled.
- Blocks direct editor access to collections outside the allowed subtree.
- Blocks direct editor access to objects linked outside the allowed subtree.
- Blocks object creation/edit entry via `collection_id` when the selected collection is outside the allowed subtree.
- Adds clear user-facing errors in the editor when an invalid parent or collection context is selected.

## Configuration

Edit:

- `app/plugins/groupCollectionRootMap/conf/plugin.conf`

Settings:

- `enable`: `1` to enable.
- `required_role`: role `name`, `code`, or `id` required for checks to apply.
- `bypass_for_administrators`: if `1`, administrators bypass restrictions.
- `require_mapping_for_all_users`: if `1`, required-role users with no user mapping are blocked.
- `allow_root_parent_assignment`: if `1`, top-level parent assignment is allowed.
- `user_root_collection_map`: user_id to root collection ID list.

Default `plugin.conf` values are intentionally safe/no-op for source control (`enable = 0`, empty mapping). Set real values in `plugin.local.conf` on the server.

## Server-local config (recommended)

To avoid re-editing after deploy syncs:

1. Keep deploy defaults in `conf/plugin.conf`.
2. Create `conf/plugin.local.conf` on the server (from `conf/plugin.local.conf.example`).
3. Put environment-specific values (especially `user_root_collection_map`) in `plugin.local.conf`.

If present, `plugin.local.conf` keys override `plugin.conf` keys.

For `rsync`, exclude the server-local file from deploys:

```bash
rsync ... --exclude='app/plugins/groupCollectionRootMap/conf/plugin.local.conf' ...
```

Example:

```conf
required_role = cataloguer
user_root_collection_map = {
  12 = [101],
  34 = [202, 203]
}
```

## Notes

- No theme changes are required.
- For invalid creates, the plugin blocks insert and reports an explicit message.
- For invalid updates, the plugin preserves the existing parent and reports an explicit message.
- Visibility filtering is currently applied in hierarchy bundle/lookup interactions and direct collection/object editor access.
- Object scope is determined from `ca_objects_x_collections_hierarchy_relationship_type` when set; otherwise all related collections are considered.
- If `ca_objects_x_collections_hierarchy_enabled` is enabled, mixed object+collection hierarchy browsing is filtered by this plugin via `RestrictedObjectCollectionHierarchyController`.
- Collection/object search and browse screens are not yet subtree-filtered by this plugin; those flows will need a second pass because Providence does not expose a clean application-plugin hook at the point results are built.
