#!/usr/bin/env bash
# Run WordPress Plugin Check (PCP) against the local wp-env plugin mount.
# CI and the GitHub Release workflow check the packaged zip instead
# (see .github/actions/plugin-check). Keep excludes in sync with that action
# and with bin/package-plugin.sh.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

SLUG_OVERRIDE="ax402-for-woocommerce"
# wp-env mounts ./plugin as folder name "plugin" (see bin/seed-wp-env.sh).
MOUNTED_SLUG="plugin"

if [[ ! -x "$ROOT/node_modules/.bin/wp-env" ]]; then
  echo "wp-env is not installed. Run: nvm use && npm install" >&2
  exit 1
fi

echo "Checking that wp-env is running (npm run env:start)…"
if ! npx wp-env run cli -- wp core is-installed >/dev/null 2>&1; then
  echo "wp-env is not running or WordPress is not installed." >&2
  echo "Start it with: nvm use && npm run env:start" >&2
  exit 1
fi

echo "Installing Plugin Check…"
npx wp-env run cli -- wp plugin install plugin-check --activate --force

echo "Running Plugin Check (stable checks, production excludes)…"
# --require loads PCP's WP-CLI command; --slug matches the WordPress.org folder name.
npx wp-env run cli -- wp plugin check "$MOUNTED_SLUG" \
  --slug="$SLUG_OVERRIDE" \
  --exclude-directories=src,node_modules,vendor,assets/demo-products \
  --exclude-files=webpack.config.js,.eslintrc.js,package-lock.json,composer.lock \
  --format=table \
  --require=./wp-content/plugins/plugin-check/cli.php

echo "Plugin Check finished (errors fail this script; warnings do not)."
