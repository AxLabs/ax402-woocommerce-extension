#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

npm --prefix plugin run build
# WordPress.org prefers ABSPATH guards on every PHP file, including webpack assets.
python3 - <<'PY'
from pathlib import Path
for path in Path("plugin/build").glob("*.asset.php"):
    text = path.read_text()
    if "ABSPATH" in text:
        continue
    if text.startswith("<?php"):
        body = text[5:].lstrip()
    else:
        body = text.lstrip()
    path.write_text("<?php\ndefined('ABSPATH') || exit;\n" + body)
    print(f"guarded {path}")
PY

VERSION=$(node -p "require('./plugin/package.json').version")
SLUG="ax402-for-woocommerce"
OUT="dist/${SLUG}-${VERSION}.zip"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p dist
rm -f "$OUT"

mkdir -p "$STAGE/$SLUG"
# Production plugin tree only (no node_modules, vendor, src, lockfiles, webpack).
rsync -a \
  --exclude 'node_modules/' \
  --exclude 'vendor/' \
  --exclude 'src/' \
  --exclude 'webpack.config.js' \
  --exclude '.eslintrc.js' \
  --exclude '*.map' \
  --exclude 'package-lock.json' \
  --exclude 'composer.lock' \
  --exclude 'assets/demo-products/' \
  --exclude '.DS_Store' \
  plugin/ "$STAGE/$SLUG/"

# Ensure LICENSE is present even if missing from plugin/.
if [[ ! -f "$STAGE/$SLUG/LICENSE" && -f LICENSE ]]; then
  cp LICENSE "$STAGE/$SLUG/LICENSE"
fi

(
  cd "$STAGE"
  zip -r "$ROOT/$OUT" "$SLUG"
)

echo "Wrote $OUT"
