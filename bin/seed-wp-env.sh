#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Load local secrets for gateway seed (never commit .env).
if [[ -f "$ROOT/.env" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "$ROOT/.env"
  set +a
fi

npx wp-env run cli wp option update woocommerce_currency USD
npx wp-env run cli wp option update woocommerce_default_country US:CA
# Sub-cent demo SKUs need more than WooCommerce's default 2 decimals.
npx wp-env run cli wp option update woocommerce_price_num_decimals 4
# WooCommerce enables "Coming soon" on fresh installs — keep the demo shop public.
npx wp-env run cli wp option update woocommerce_coming_soon no
# wp-env installs Woo from the zip URL as folder/slug `woocommerce.latest-stable`.
npx wp-env run cli wp plugin activate woocommerce.latest-stable || \
  npx wp-env run cli wp plugin activate woocommerce || true
npx wp-env run cli wp plugin activate plugin || true
# Pinned WC zip bumps leave the store on the previous schema until this runs.
npx wp-env run cli wp wc update --user=1 || true

# Enable gateway. Host .env is written into the mounted plugin dir (wp-env PHP
# getenv() cannot see the host shell). Merge so empty values never wipe secrets.
SEED_GATEWAY_FILE="$ROOT/plugin/.seed-gateway.json"
python3 - <<'PY' > "$SEED_GATEWAY_FILE"
import json, os

def yes_no(name, default):
    raw = (os.environ.get(name) or "").strip().lower()
    if raw in ("no", "0", "false", "off"):
        return "no"
    if raw in ("yes", "1", "true", "on"):
        return "yes"
    return default

print(json.dumps({
  "base_url": os.environ.get("AX402_BASE_URL") or "https://api.ax402.io",
  "pay_to_address": os.environ.get("AX402_PAY_TO_ADDRESS") or "",
  "pay_to_hedera_account_id": os.environ.get("AX402_PAY_TO_HEDERA_ACCOUNT_ID") or "",
  "walletconnect_project_id": os.environ.get("AX402_WALLETCONNECT_PROJECT_ID") or "",
  "network_mode": os.environ.get("AX402_NETWORK") or "sepolia",
  "api_key": os.environ.get("AX402_API_KEY") or "",
  "ucp_enabled": yes_no("AX402_UCP_ENABLED", "yes"),
}))
PY
trap 'rm -f "$SEED_GATEWAY_FILE"' EXIT

npx wp-env run cli wp eval '
$path = WP_CONTENT_DIR . "/plugins/plugin/.seed-gateway.json";
$incoming = is_readable($path) ? json_decode((string) file_get_contents($path), true) : [];
if (!is_array($incoming)) {
  $incoming = [];
}
@unlink($path);

$gateway = [
  "enabled" => "yes",
  "title" => "Pay with Ax402",
  "description" => "Pay with a wallet token via Ax402",
  "base_url" => (string) ($incoming["base_url"] ?? "https://api.ax402.io"),
  "pay_to_address" => (string) ($incoming["pay_to_address"] ?? ""),
  "pay_to_hedera_account_id" => (string) ($incoming["pay_to_hedera_account_id"] ?? ""),
  "walletconnect_project_id" => (string) ($incoming["walletconnect_project_id"] ?? ""),
  "network_mode" => (string) ($incoming["network_mode"] ?? "sepolia"),
];
$current = get_option("woocommerce_ax402_settings", []);
if (!is_array($current)) {
  $current = [];
}
$gateway["ucp_enabled"] = (($incoming["ucp_enabled"] ?? "yes") === "yes") ? "yes" : "no";
foreach (["base_url", "pay_to_address", "pay_to_hedera_account_id", "walletconnect_project_id", "network_mode"] as $key) {
  if ($gateway[$key] === "" && !empty($current[$key])) {
    $gateway[$key] = (string) $current[$key];
  }
}
update_option("woocommerce_ax402_settings", array_merge($current, $gateway));

if (class_exists("Ax402_WC_Settings")) {
  $plugin_update = [
    "base_url" => $gateway["base_url"],
    "network_mode" => $gateway["network_mode"],
  ];
  if ($gateway["pay_to_address"] !== "") {
    $plugin_update["pay_to_address"] = $gateway["pay_to_address"];
  }
  if ($gateway["pay_to_hedera_account_id"] !== "") {
    $plugin_update["pay_to_hedera_account_id"] = $gateway["pay_to_hedera_account_id"];
  }
  if ($gateway["walletconnect_project_id"] !== "") {
    $plugin_update["walletconnect_project_id"] = $gateway["walletconnect_project_id"];
  }
  $api_key = trim((string) ($incoming["api_key"] ?? ""));
  if ($api_key !== "") {
    $plugin_update["api_key"] = $api_key;
  }
  $plugin_update["ucp_enabled"] = $gateway["ucp_enabled"];
  Ax402_WC_Settings::update($plugin_update);
}

$available = (new Ax402_WC_Gateway_Ax402())->is_available() ? "yes" : "no";
echo "gateway settings saved; is_available={$available}\n";
'

# Demo catalog with images (idempotent by SKU).
npx wp-env run cli wp eval '
function ax402_seed_attach_image(string $absolute_path, string $title): int {
  if (!is_readable($absolute_path)) {
    echo "missing image: {$absolute_path}\n";
    return 0;
  }

  // Copy first: media_handle_sideload treats tmp_name like an upload temp and
  // moves/deletes it — never point it at the tracked plugin assets.
  $tmp = wp_tempnam(basename($absolute_path));
  if ($tmp === false || !@copy($absolute_path, $tmp)) {
    echo "copy failed for {$absolute_path}\n";
    return 0;
  }

  $file = [
    "name" => basename($absolute_path),
    "tmp_name" => $tmp,
  ];
  $id = media_handle_sideload($file, 0, $title);
  if (is_wp_error($id)) {
    @unlink($tmp);
    // Fallback: read source bytes into uploads without touching the asset file.
    require_once ABSPATH . "wp-admin/includes/file.php";
    require_once ABSPATH . "wp-admin/includes/media.php";
    require_once ABSPATH . "wp-admin/includes/image.php";
    $upload = wp_upload_bits(basename($absolute_path), null, file_get_contents($absolute_path));
    if (!empty($upload["error"])) {
      echo "upload failed for {$absolute_path}: {$upload["error"]}\n";
      return 0;
    }
    $filetype = wp_check_filetype($upload["file"]);
    $attachment = [
      "post_mime_type" => $filetype["type"] ?: "image/png",
      "post_title" => $title,
      "post_content" => "",
      "post_status" => "inherit",
    ];
    $id = wp_insert_attachment($attachment, $upload["file"]);
    if (is_wp_error($id) || !$id) {
      echo "attach failed for {$absolute_path}\n";
      return 0;
    }
    $meta = wp_generate_attachment_metadata($id, $upload["file"]);
    wp_update_attachment_metadata($id, $meta);
  }
  return (int) $id;
}

function ax402_seed_product(array $p): void {
  $existing = get_posts([
    "post_type" => "product",
    "post_status" => "any",
    "meta_key" => "_sku",
    "meta_value" => $p["sku"],
    "fields" => "ids",
    "numberposts" => 1,
  ]);
  if ($existing) {
    $product = wc_get_product((int) $existing[0]);
    echo "exists {$p["sku"]} id=" . $product->get_id() . "\n";
  } else {
    $product = new WC_Product_Simple();
    $product->set_sku($p["sku"]);
  }

  $product->set_name($p["name"]);
  $product->set_status("publish");
  $product->set_catalog_visibility("visible");
  $product->set_description($p["description"]);
  $product->set_short_description($p["short"]);
  $product->set_regular_price($p["price"]);
  $product->set_virtual(!empty($p["virtual"]));
  $product->set_downloadable(!empty($p["downloadable"]));
  $product->set_sold_individually(false);

  // Sideload when missing. Do not re-copy when the file is already on disk —
  // media_handle_sideload used to delete tracked plugin PNGs if tmp_name pointed
  // at those assets (copy-first in ax402_seed_attach_image avoids that).
  $current_image_id = (int) $product->get_image_id();
  $current_file = $current_image_id > 0 ? (string) get_attached_file($current_image_id) : "";
  $need_image = !empty($p["image"]) && ($current_image_id <= 0 || $current_file === "" || !file_exists($current_file));
  if ($need_image) {
    $image_id = ax402_seed_attach_image($p["image"], $p["name"]);
    if ($image_id > 0) {
      $product->set_image_id($image_id);
    }
  }

  $id = $product->save();
  echo "seeded {$p["sku"]} id={$id} price={$p["price"]}\n";
}

$base = WP_CONTENT_DIR . "/plugins/plugin/assets/demo-products";
$products = [
  [
    "sku" => "ax402-micropay",
    "name" => "Ax402 E2E Micropay",
    "price" => "0.01",
    "virtual" => true,
    "downloadable" => false,
    "short" => "Tiny USD checkout demo item.",
    "description" => "A one-cent demo product for Ax402 wallet and agent checkout tests.",
    "image" => $base . "/demo-product-chip.png",
  ],
  [
    "sku" => "ax402-spark",
    "name" => "Ax402 Spark Note",
    "price" => "0.02",
    "virtual" => true,
    "downloadable" => true,
    "short" => "Two-cent virtual note.",
    "description" => "A lightweight digital note used to demo Ax402 payments at \$0.02.",
    "image" => $base . "/demo-product-notebook.png",
  ],
  [
    "sku" => "ax402-signal",
    "name" => "Ax402 Signal Pulse",
    "price" => "0.001",
    "virtual" => true,
    "downloadable" => false,
    "short" => "Sub-cent signal credit.",
    "description" => "A fractional demo product priced at \$0.001 for micropayment demos.",
    "image" => $base . "/demo-product-token.png",
  ],
  [
    "sku" => "ax402-dust",
    "name" => "Ax402 Dust Credit",
    "price" => "0.0003",
    "virtual" => true,
    "downloadable" => false,
    "short" => "Ultra-small dust credit.",
    "description" => "An ultra-small demo SKU at \$0.0003 to exercise low-amount settlement.",
    "image" => $base . "/demo-product-guide.png",
  ],
  [
    "sku" => "ax402-pass",
    "name" => "Ax402 Access Pass",
    "price" => "0.10",
    "virtual" => true,
    "downloadable" => false,
    "short" => "Ten-cent access pass.",
    "description" => "A \$0.10 demo product for mid-range micropayment checkout tests.",
    "image" => $base . "/demo-product-pass.png",
  ],
  [
    "sku" => "ax402-pack",
    "name" => "Ax402 Starter Pack",
    "price" => "0.25",
    "virtual" => true,
    "downloadable" => true,
    "short" => "Quarter-dollar starter pack.",
    "description" => "A \$0.25 demo pack for larger micropayment checkout tests.",
    "image" => $base . "/demo-product-pack.png",
  ],
  [
    "sku" => "ax402-ship-box",
    "name" => "Ax402 Ship Box",
    "price" => "0.10",
    "virtual" => false,
    "downloadable" => false,
    "short" => "Physical demo SKU for UCP shipping.",
    "description" => "A \$0.10 physical demo product used to exercise UCP tax and shipping.",
    "image" => $base . "/demo-product-pack.png",
  ],
];

foreach ($products as $product) {
  ax402_seed_product($product);
}

update_option("woocommerce_calc_shipping", "yes");
update_option("woocommerce_ship_to_countries", "all");
if (class_exists("WC_Shipping_Zone") && class_exists("WC_Shipping_Zones")) {
  $zone_id = 0;
  foreach (WC_Shipping_Zones::get_zones() as $existing_zone) {
    if (($existing_zone["zone_name"] ?? "") === "Ax402 UCP E2E") {
      $zone_id = (int) ($existing_zone["zone_id"] ?? $existing_zone["id"] ?? 0);
      break;
    }
  }
  if ($zone_id <= 0) {
    $zone = new WC_Shipping_Zone();
    $zone->set_zone_name("Ax402 UCP E2E");
    $zone->save();
    $zone->add_location("US", "country");
    $instance_id = $zone->add_shipping_method("flat_rate");
    $option_key = "woocommerce_flat_rate_" . (int) $instance_id . "_settings";
    $current_method = get_option($option_key, []);
    if (!is_array($current_method)) {
      $current_method = [];
    }
    $current_method["title"] = "Flat rate";
    $current_method["cost"] = "0.05";
    $current_method["tax_status"] = "taxable";
    update_option($option_key, $current_method);
    echo "seeded shipping zone Ax402 UCP E2E flat_rate={$instance_id}\n";
  } else {
    echo "exists shipping zone Ax402 UCP E2E id={$zone_id}\n";
  }
}

echo "Seed complete.\n";
'

echo "Seed complete."
