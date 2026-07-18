#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

npm --prefix plugin run build
VERSION=$(node -p "require('./plugin/package.json').version")
OUT="dist/ax402-woocommerce-${VERSION}.zip"
mkdir -p dist

rm -f "$OUT"
(
  cd plugin
  zip -r "../$OUT" . \
    -x "node_modules/*" \
    -x "vendor/*" \
    -x "src/*" \
    -x "webpack.config.js" \
    -x "*.map"
)

echo "Wrote $OUT"
