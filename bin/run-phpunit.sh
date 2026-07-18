#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if command -v php >/dev/null 2>&1 && [[ -x "$ROOT/plugin/vendor/bin/phpunit" ]]; then
  exec "$ROOT/plugin/vendor/bin/phpunit" -c "$ROOT/phpunit.xml.dist" "$@"
fi

docker run --rm \
  -v "$ROOT:/app" \
  -w /app \
  -e AX402_API_KEY="${AX402_API_KEY:-}" \
  -e AX402_BASE_URL="${AX402_BASE_URL:-https://api.ax402.io}" \
  -e AX402_PAY_TO_ADDRESS="${AX402_PAY_TO_ADDRESS:-}" \
  composer:2 \
  bash -lc 'cd plugin && composer install --no-interaction && ../plugin/vendor/bin/phpunit -c ../phpunit.xml.dist '"$*"
