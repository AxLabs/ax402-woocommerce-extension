#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

ensure_vendor() {
  if [[ -x "$ROOT/plugin/vendor/bin/phpunit" ]]; then
    return 0
  fi
  if ! command -v composer >/dev/null 2>&1; then
    return 1
  fi
  echo "Installing PHP dependencies via composer…"
  composer install --no-interaction --working-dir="$ROOT/plugin"
  [[ -x "$ROOT/plugin/vendor/bin/phpunit" ]]
}

if command -v php >/dev/null 2>&1; then
  if ensure_vendor; then
    exec "$ROOT/plugin/vendor/bin/phpunit" -c "$ROOT/phpunit.xml.dist" "$@"
  fi
  echo "PHP is available but composer/phpunit is not. Install composer or fix Docker." >&2
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "Neither local PHPUnit nor Docker is available." >&2
  exit 1
fi

if ! docker info >/dev/null 2>&1; then
  echo "Docker is installed but not usable (daemon down or API error)." >&2
  exit 1
fi

docker run --rm \
  --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  -e COMPOSER_HOME=/tmp/composer \
  -v "$ROOT:/app" \
  -w /app \
  -e AX402_API_KEY="${AX402_API_KEY:-}" \
  -e AX402_BASE_URL="${AX402_BASE_URL:-https://api.ax402.io}" \
  -e AX402_PAY_TO_ADDRESS="${AX402_PAY_TO_ADDRESS:-}" \
  composer:2 \
  bash -lc 'mkdir -p /tmp/composer && cd plugin && composer install --no-interaction && ../plugin/vendor/bin/phpunit -c ../phpunit.xml.dist '"$*"
