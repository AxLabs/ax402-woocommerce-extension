#!/usr/bin/env bash
# Bump the plugin SemVer across all canonical version sources.
# Usage: bash bin/bump-version.sh <major.minor.patch>
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="${1:-}"
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
  echo "Usage: bash bin/bump-version.sh <SemVer>   e.g. 0.2.0" >&2
  exit 1
fi

PLUGIN_MAIN="$ROOT/plugin/ax402-for-woocommerce.php"
README_TXT="$ROOT/plugin/readme.txt"

for f in "$PLUGIN_MAIN" "$README_TXT" "$ROOT/package.json" "$ROOT/plugin/package.json"; do
  if [[ ! -f "$f" ]]; then
    echo "Missing required file: $f" >&2
    exit 1
  fi
done

# Prefer Python for reliable in-place edits (avoid perl $1 / $10.x.y pitfalls).
python3 - "$VERSION" "$PLUGIN_MAIN" "$README_TXT" <<'PY'
import re
import sys
from pathlib import Path

version, plugin_main, readme_txt = sys.argv[1], Path(sys.argv[2]), Path(sys.argv[3])

main = plugin_main.read_text()
main, n1 = re.subn(
    r"(?m)^(\s*\* Version:\s*)\S+",
    r"\g<1>" + version,
    main,
    count=1,
)
main, n2 = re.subn(
    r"define\('AX402_WC_VERSION',\s*'[^']*'\)",
    f"define('AX402_WC_VERSION', '{version}')",
    main,
    count=1,
)
if n1 != 1 or n2 != 1:
    raise SystemExit(f"Failed to update plugin main (header={n1}, const={n2})")
plugin_main.write_text(main)

readme = readme_txt.read_text()
readme, n3 = re.subn(
    r"(?m)^(Stable tag:\s*)\S+",
    r"\g<1>" + version,
    readme,
    count=1,
)
if n3 != 1:
    raise SystemExit(f"Failed to update readme.txt Stable tag (n={n3})")

# Pointer only — full notes live on the GitHub Release (see RELEASE.md).
changelog_heading = f"= {version} ="
release_url = (
    "https://github.com/AxLabs/ax402-woocommerce-extension/releases/tag/v"
    + version
)
stub = f"{changelog_heading}\n[GitHub release v{version}]({release_url})\n\n"
marker = "== Changelog ==\n"
if changelog_heading not in readme:
    if marker not in readme:
        raise SystemExit("readme.txt missing == Changelog ==")
    # Insert after the Changelog heading and any intro paragraph, before the
    # first existing = X.Y.Z = entry.
    first_entry = re.search(r"\n= [0-9].* =\n", readme)
    if first_entry and first_entry.start() > readme.find(marker):
        insert_at = first_entry.start() + 1
        readme = readme[:insert_at] + stub + readme[insert_at:]
    else:
        readme = readme.replace(marker, marker + "\n" + stub, 1)
readme_txt.write_text(readme)
PY

node -e "
const fs = require('fs');
const version = process.argv[1];
for (const path of ['package.json', 'plugin/package.json']) {
  const pkg = JSON.parse(fs.readFileSync(path, 'utf8'));
  pkg.version = version;
  fs.writeFileSync(path, JSON.stringify(pkg, null, 2) + '\n');
}
" "$VERSION"

echo "Bumped version sources to ${VERSION}"
bash "$ROOT/bin/check-version.sh" "$VERSION"
