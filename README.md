# Providence plugins

Custom application plugins for CollectiveAccess Providence. Each plugin can be
installed, enabled and updated independently. This repository contains plugin
source; Providence itself and site-local configuration stay outside it.

| Plugin | Purpose |
| --- | --- |
| [collectionSibling](collectionSibling/README.md) | Add a sibling object directly in the same hierarchy collection |
| [groupCollectionRootMap](groupCollectionRootMap/README.md) | Scope collection/object editor and hierarchy access to assigned collection roots |
| [representationFilenameIdno](representationFilenameIdno/README.md) | Derive representation identifiers and blank preferred labels from uploaded filenames |

The collection access plugin does not filter all search/browse results. Read its
documented boundaries before relying on it. Consolidation into this repository
does not change any plugin's access policy or runtime behavior.

## Install or update a plugin

Use the installation instructions in that plugin's README. Copy only the chosen
plugin folder into Providence's `app/plugins/` directory with its existing name:
for example, `collectionSibling/` becomes `app/plugins/collectionSibling/`.
The repository root, scripts and documentation are not an application plugin.

All three shared `conf/plugin.conf` files default to disabled. Enable plugins
through the installation's ignored `conf/plugin.local.conf` files, using the
corresponding examples as a starting point. Preserve those local files during
updates. A checkout or source push does not install or enable any plugin.

Providence upgrades must preserve the installed custom plugin folders and local
configuration. Recheck the relevant plugin's documented compatibility after an
upgrade. Source-control organization does not change the installation paths.

## Development checks

From the repository root:

```sh
bash scripts/check.sh
bash scripts/check.sh --browser
```

Both commands lint all tracked PHP and JavaScript files, run the sibling
plugin's 39 standalone PHP assertions, and check Git whitespace. `--browser`
also runs Chromium interaction checks. The other two plugins currently have no
standalone behavior suites; PHP lint does not verify their live behavior.

The tests require a trusted Providence reference checkout. In the existing
workspace layout, a sibling `../providence/` checkout is detected automatically.
For a standalone clone, set the reference explicitly:

```sh
PROVIDENCE_REFERENCE_ROOT=/path/to/trusted/providence bash scripts/check.sh
```

The sibling suite was developed against Providence 2.0.10. It reads the actual
hierarchy Add template and navigation handler without bootstrapping Providence
or reading installation connection settings. PHP and Node.js are required.
Browser checks additionally require Playwright and its matching Chromium;
`NODE_PATH` can point to an external directory containing the Playwright package.

Run checks for a specific plugin from that plugin's folder using its README.
Keep tests synthetic. Installation, live saves and production validation remain
separate operator actions.

## Repository history

The three original repositories were imported with their complete histories and
original commit IDs. See [history and migration notes](docs/HISTORY.md) for
source revisions and commands to inspect their earlier paths. Continue plugin
development here. The `providence-widgets` repository remains separate.

Retain each plugin's existing license and attribution notices. The sibling
plugin includes its GPL license in [collectionSibling/LICENSE](collectionSibling/LICENSE).
