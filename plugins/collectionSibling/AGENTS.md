# collectionSibling

Standalone Providence application plugin; keep core and installed plugin copies
untouched. Read README.md before changes. Preserve server-local configuration.

- Use `hookBundleFormHTML` and the normal object editor; do not add record writes
  or duplicate Providence's save/access handling.
- Do not choose arbitrarily when more than one hierarchy collection is present.
- Run the standalone PHP tests, browser tests, PHP/JavaScript syntax checks and
  `git diff --check` documented in README.md.
- Use synthetic records only. Tests must not bootstrap Providence or a database.
- Deployment, cache clearing and production record creation require explicit
  authorization. A source commit is not deployment authorization.
