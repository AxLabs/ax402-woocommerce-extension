=== Ax402 for WooCommerce ===
Contributors: axlabs
Tags: woocommerce, payments, x402, usdc, crypto
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept USDC payments via Ax402 / x402 for humans and AI agents.

== Description ==

Ax402 for WooCommerce adds a payment gateway that prices each order as an Ax402 gateway endpoint. Shoppers pay with a wallet UI; agents pay the same URL with buyer SDKs or the Ax402 CLI.

== Installation ==

1. Upload the `ax402-woocommerce` plugin folder to `/wp-content/plugins/`
2. Activate the plugin (WooCommerce required)
3. Set store currency to USD
4. Configure WooCommerce → Settings → Payments → Ax402

== Changelog ==

= 0.1.0 =
* Initial release: gateway, fulfill upstream, agent API, pay page, Blocks support
