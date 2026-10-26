#!/usr/bin/env bash
set -euo pipefail

if [[ $# -gt 1 || ( $# -eq 1 && "$1" != "--browser" ) ]]; then
    printf 'Usage: bash scripts/check.sh [--browser]\n' >&2
    exit 2
fi

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

while IFS= read -r -d '' source_file; do
    case "$source_file" in
        *.php) php -l "$source_file" ;;
        *.js) node --check "$source_file" ;;
    esac
done < <(git ls-files -z -- '*.php' '*.js')

php plugins/collectionSibling/tests/plugin_test.php
php plugins/groupCollectionRootMap/tests/acl_test.php
if [[ "${1:-}" == "--browser" ]]; then
    node plugins/collectionSibling/tests/browser_test.js
fi
git diff --check
git diff --cached --check
