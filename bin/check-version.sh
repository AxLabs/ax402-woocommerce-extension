#!/usr/bin/env bash
# Verify all version sources agree. Optional arg: expected SemVer (e.g. from git tag).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

EXPECTED="${1:-}"
EXPECTED="${EXPECTED#v}" # allow v0.1.0

php_header=$(grep -E '^\s*\* Version:' plugin/ax402-woocommerce.php | head -1 | sed -E 's/.*Version:[[:space:]]*//')
php_const=$(grep -E "define\('AX402_WC_VERSION'" plugin/ax402-woocommerce.php | sed -E "s/.*'([^']+)'.*/\1/")
stable=$(grep -E '^Stable tag:' plugin/readme.txt | head -1 | sed -E 's/Stable tag:[[:space:]]*//')
root_pkg=$(node -p "require('./package.json').version")
plugin_pkg=$(node -p "require('./plugin/package.json').version")

fail=0
check() {
  local label="$1" value="$2"
  if [[ -n "$EXPECTED" && "$value" != "$EXPECTED" ]]; then
    echo "Mismatch: $label='$value' (expected '$EXPECTED')" >&2
    fail=1
  fi
}

check "plugin header Version" "$php_header"
check "AX402_WC_VERSION" "$php_const"
check "readme.txt Stable tag" "$stable"
check "package.json" "$root_pkg"
check "plugin/package.json" "$plugin_pkg"

if [[ "$php_header" != "$php_const" \
   || "$php_header" != "$stable" \
   || "$php_header" != "$root_pkg" \
   || "$php_header" != "$plugin_pkg" ]]; then
  echo "Version sources are out of sync:" >&2
  echo "  plugin header:       $php_header" >&2
  echo "  AX402_WC_VERSION:    $php_const" >&2
  echo "  readme Stable tag:   $stable" >&2
  echo "  package.json:        $root_pkg" >&2
  echo "  plugin/package.json: $plugin_pkg" >&2
  fail=1
fi

if [[ "$fail" -ne 0 ]]; then
  exit 1
fi

echo "OK version=${php_header}"
