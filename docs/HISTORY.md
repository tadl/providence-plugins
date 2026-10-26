# Repository consolidation

The following source histories were imported on October 26, 2026 using Git
subtree imports without squashing or rewriting the original commits.

| Plugin folder | Previous source | Imported revision |
| --- | --- | --- |
| `collectionSibling/` | Local source repository, not previously pushed | `8b91b8b074e0c56847716f22e5e2668a35a13945` |
| `groupCollectionRootMap/` | [tadl/groupCollectionRootMap](https://github.com/tadl/groupCollectionRootMap) | `6289e455ac955bad51964aac2f80773a8f0bc625` |
| `representationFilenameIdno/` | [tadl/representationFilenameIdno](https://github.com/tadl/representationFilenameIdno) | `52c53b5f895b36e9278b4549c2b6eb68ff0efdd0` |

The previous source revisions remain ancestors of `main`. Before each import,
files lived at the root of their individual repository, so earlier commits use
those original paths. For example:

```sh
git log --oneline 6289e455ac955bad51964aac2f80773a8f0bc625
git show 6289e455ac955bad51964aac2f80773a8f0bc625:groupCollectionRootMapPlugin.php
git log --all --graph --oneline
```

Use the plugin folder prefix for new commits. The plugin folders are ordinary
tracked directories, with no nested `.git` directories or Git submodules.

This consolidation did not modify Providence, installed plugin copies, widget
source, collection access behavior or production configuration. The original
source checkouts were retained as local recovery copies outside the active
monorepo. This migration does not delete or archive GitHub repositories. Once
the monorepo push is verified, the owner can retire the old GitHub repositories.
Future source changes belong in this monorepo.
