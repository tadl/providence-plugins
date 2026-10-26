# collectionSibling

Providence application plugin that adds **next to (sibling)** for an object
directly associated with a collection. Uses `hookBundleFormHTML`; no core or
theme files are replaced.

## Behavior

Open an existing object, expand its hierarchy, choose **Add**, select the object
type and **next to (sibling)**, then use the existing plus button. Providence
opens its normal new-object form with the same collection selected. Complete
the new record and save normally. Save edits to the current object first.

The plugin only adds the option when all of these hold:

- The existing object has no object parent and has exactly one distinct
  collection linked by the configured hierarchy relationship type.
- Combined collection/object hierarchies are enabled, with strict object type
  hierarchy enforcement disabled.
- The user can create objects, edit the hierarchy bundle and read the collection.
- Providence has rendered its native Add controls for this placement.

Objects with an object parent keep Providence's existing sibling action. Missing
or ambiguous collection membership, new records, batch forms, read-only controls
and inaccessible/deleted collections get no added option. Other collection
relationship types are ignored.

This opens a fresh record; it does not duplicate labels, metadata, identifiers,
media, collection relationship metadata or other relationships. Collection
membership is created by Providence only when the user saves the new record.
Normal identifier inheritance and collection sorting still apply; the plugin
does not force the new record immediately after the source object.

## Installation

1. Copy this directory to `app/plugins/collectionSibling` in the intended
   Providence installation. Preserve other installed plugins and local files.
2. Copy `conf/plugin.local.conf.example` to `conf/plugin.local.conf` in that
   installed directory. The example enables the plugin; shared `plugin.conf`
   defaults to disabled. Keep the local file private and preserve it on updates.
3. Confirm the installation already uses these `app.conf` settings:

   ```conf
   ca_objects_x_collections_hierarchy_enabled = 1
   ca_objects_x_collections_hierarchy_relationship_type = part_of
   ca_objects_enforce_strict_type_hierarchy = 0
   ```

   `part_of` is a synthetic example: retain the installation's actual existing
   hierarchy relationship type. These are prerequisites, not instructions to
   change the site's hierarchy policy.
4. Check the workflow with an authorized cataloguing account. Opening the form
   should select the intended collection. A live save creates a real record and
   should be an explicitly authorized pilot.

Providence discovers available application plugins automatically. Follow the
installation's normal PHP/OPcache update procedure if required; a routine
service restart or cache purge is not part of this plugin's installation steps.
To disable, set `enable = 0` in the installed local config. Existing records and
collection relationships are unaffected.

## Permissions and upgrades

The plugin adds a UI shortcut and implements no save endpoint or database writes.
It uses the same `collection_id` new-object route as the collection editor's
**Add object** action. Native editor/save permission and CSRF checks, and
`groupCollectionRootMap` editor/save collection checks, remain responsible for
authorization. This plugin is not a replacement for collection access controls.

Maintain this plugin in its folder in the shared `providence-plugins` repository.
Preserve the installed custom plugin directory and local configuration during
upgrades. Source compatibility
is checked against Providence **2.0.10**. Integration depends on the bundle HTML
hook, native Add row IDs and the existing collection creation route. Run the
tests after upgrading and repeat a browser smoke test; persistence does not
guarantee compatibility with every future UI change. The plugin requires the
singular `ca_objects_x_collections_hierarchy_relationship_type` setting used by
the inspected object editor; installations using only newer plural settings
need a compatibility review before enabling it.

## Offline checks

From this plugin's folder (`plugins/collectionSibling/` in the monorepo):

```sh
php tests/plugin_test.php
php -l collectionSiblingPlugin.php
node --check assets/collection-sibling.js
node tests/browser_test.js
git diff --check
```

The PHP suite reads the trusted sibling `../../../providence` reference checkout's
actual Add template and navigation handler, with synthetic framework boundaries.
Set `PROVIDENCE_REFERENCE_ROOT` to use a different trusted reference checkout.
It never bootstraps the application or reads installation connection settings.
Browser tests need Playwright available to Node and a matching Chromium browser;
an external tools directory can be supplied through `NODE_PATH`. There are no
production runtime dependencies beyond Providence and browser JavaScript.

Offline tests do not prove a live database save or deployed access configuration.
Source development and deployment are separate operations.
