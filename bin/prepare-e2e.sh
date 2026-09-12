#!/usr/bin/env bash
# Prepare / verify the local E2E environment: seed catalog + gateway, optionally
# point WordPress + Ax402 upstream at WP_BASE_URL (public tunnel).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ -f "$ROOT/.env" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "$ROOT/.env"
  set +a
fi

echo "==> Seeding wp-env (products, gateway, coming-soon off)…"
bash "$ROOT/bin/seed-wp-env.sh"

WP_BASE_URL_VAL="${WP_BASE_URL:-}"
WP_BASE_URL_VAL="${WP_BASE_URL_VAL%/}"

if [[ -n "$WP_BASE_URL_VAL" ]]; then
  echo "==> Pointing WordPress at public origin: ${WP_BASE_URL_VAL}"
  npx wp-env run cli wp config set WP_HOME "$WP_BASE_URL_VAL"
  npx wp-env run cli wp config set WP_SITEURL "$WP_BASE_URL_VAL"
  # Keep DB options in sync too — mismatched home/siteurl makes
  # http://localhost:8888 redirect to http://localhost/ (port 80) and look "down".
  npx wp-env run cli wp option update home "$WP_BASE_URL_VAL"
  npx wp-env run cli wp option update siteurl "$WP_BASE_URL_VAL"

  echo "==> Syncing Ax402 API upstream_base_url (onboard / refresh)…"
  # Escape for PHP without requiring host `php` (wp-env images have PHP; macOS hosts often do not).
  BASE_PHP="$(
    WP_BASE_URL_VAL="$WP_BASE_URL_VAL" python3 -c 'import json, os; print(json.dumps(os.environ["WP_BASE_URL_VAL"]))'
  )"
  npx wp-env run cli wp eval "
\$base = ${BASE_PHP};
\$client = Ax402_WC_Settings::client();
if (\$client === null) {
  echo \"skip upstream: missing API client (API key?)\n\";
  return;
}
try {
  \$onboarded = Ax402_WC_Store_Onboarding::ensure_api(\$client);
} catch (Throwable \$e) {
  echo \"onboard failed: \" . \$e->getMessage() . \"\n\";
  return;
}
\$api_id = \$onboarded['api_id'];
\$api = \$onboarded['api'];
\$current = rtrim((string) (\$api['upstream_base_url'] ?? ''), '/');
if (\$current === rtrim(\$base, '/')) {
  echo \"upstream already matches {\$base}\n\";
} else {
  \$client->update_api(\$api_id, ['upstream_base_url' => \$base]);
  echo \"upstream updated to {\$base}\n\";
}
echo \"api_id={\$api_id} gateway_host={\$onboarded['gateway_host']}\n\";
\$cors = Ax402_WC_Gateway_Cors::ensure_store_origins(\$api_id, \$client);
if (\$cors['ok']) {
  echo \"cors origins: \" . implode(', ', \$cors['origins']) . \"\n\";
} else {
  echo \"cors sync warning: {\$cors['error']}\n\";
}
\$settings = Ax402_WC_Settings::all();
if (\$settings['hedera_api_id'] !== '') {
  echo \"legacy hedera_api_id={\$settings['hedera_api_id']} (kept for in-flight orders; new payments use api_id)\n\";
}
"
else
  echo "==> WP_BASE_URL not set — skipped tunnel / upstream sync."
  echo "    Set WP_BASE_URL in .env to your ngrok (or other) public HTTPS origin."
fi

echo "==> E2E readiness check…"
npx wp-env run cli wp eval '
$g = new Ax402_WC_Gateway_Ax402();
$available = $g->is_available() ? "yes" : "no";
$products = get_posts([
  "post_type" => "product",
  "post_status" => "publish",
  "fields" => "ids",
  "numberposts" => -1,
]);
$coming = get_option("woocommerce_coming_soon", "no");
$home = defined("WP_HOME") ? WP_HOME : get_option("home");
$s = Ax402_WC_Settings::all();
echo "is_available={$available}\n";
echo "products=" . count($products) . "\n";
echo "coming_soon={$coming}\n";
echo "WP_HOME={$home}\n";
echo "network={$s["network_mode"]}\n";
echo "api_slug={$s["api_slug"]}\n";
echo "gateway_host={$s["gateway_host"]}\n";
if ($available !== "yes") {
  echo "NOT READY: fill AX402_API_KEY + AX402_PAY_TO_ADDRESS in .env (and Hedera vars if testing Hedera) and re-run.\n";
  exit(1);
}
if (count($products) < 1) {
  echo "NOT READY: no published products.\n";
  exit(1);
}
if ($coming === "yes") {
  echo "NOT READY: WooCommerce coming soon is enabled.\n";
  exit(1);
}
echo "READY for local shop + checkout.\n";
echo "For live Ax402 settle, ensure WP_HOME is a public HTTPS URL and ngrok (or similar) is running.\n";
'

cat <<EOF

Next steps:
  1. Start tunnel (if needed):  ngrok http 8888
     Or reserved host:          ngrok http 8888 --url=YOUR_HOST.ngrok-free.dev
  2. Put that HTTPS origin in .env as WP_BASE_URL and re-run:  npm run env:e2e
  3. Programmatic pay:          npm run test:e2e-pay
  4. Manual: open WP_BASE_URL (or http://localhost:8888), checkout a demo product.

Admin: http://localhost:8888/wp-admin  (admin / password)
Docs:  docs/e2e.md
EOF
