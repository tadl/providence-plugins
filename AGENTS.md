# Providence plugins

Read README.md and the relevant plugin's README.md before changes. Nested
AGENTS.md files apply to their plugin. This is one Git repository containing
independently installed Providence application plugins.

- Keep each plugin self-contained. Do not turn plugins into implicit runtime
  dependencies of one another or change unrelated plugin behavior.
- Preserve native authorization and CSRF checks. Use supported hooks; do not
  patch Providence core or installed copies as part of source work.
- Never track installation-local configuration, user mappings, records, media,
  exports or logs. Preserve `conf/plugin.local.conf` during updates.
- Run `bash scripts/check.sh`; also run `bash scripts/check.sh --browser` for
  sibling plugin UI changes. Read README.md for trusted reference requirements.
- Preserve the imported histories. Commit from this repository root; plugin
  directories are ordinary folders, not submodules or nested repositories.
- Source pushes are separate from deployment. No production copy, cache purge,
  service restart or record write without explicit user authorization.
