=== Ax402 for WooCommerce ===
Contributors: axlabs
Tags: woocommerce, payments, crypto, usdc, x402
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept x402 / Ax402 wallet payments in WooCommerce. Buyers pay with supported stablecoins; Ax402 covers network fees.

== Description ==

Ax402 for WooCommerce adds a payment method that settles orders with Ax402 / x402. Shoppers pay from a wallet on the pay page. Agents can use the same payment URL via the Ax402 CLI or buyer SDKs.

Your catalog stays in USD. Settlement tokens come from your Ax402 platform config (for example USDC). Classic checkout and Cart / Checkout Blocks are supported. HPOS is supported.

You need an Ax402 merchant account and API key from https://ax402.io

= External services =

This plugin talks to Ax402 (operated by AxLabs GmbH) to create payment endpoints, sync platform tokens, and confirm settlements.

* When: configuring the gateway, placing an Ax402 order, loading the pay page, refreshing tokens, and verifying payment.
* Data sent: API key, pay-to wallet, order totals / amounts, order keys, selected settlement token, store origin for CORS, and settlement references returned by Ax402. No card data or wallet private keys are sent.
* Service: https://ax402.io
* Terms: https://ax402.io/terms
* Privacy: https://ax402.io/privacy
* Disclaimer: https://ax402.io/disclaimer

Chain metadata (names, explorers, public RPC hints) may be loaded from https://chainid.network/chains.json when a token does not already carry that data. Wallet / RPC calls happen in the shopper's browser only when they choose to pay.

= Source code and build =

Minified frontend assets live in `build/`. Readable source and build tooling:

https://github.com/AxLabs/ax402-woocommerce-extension

```
npm --prefix plugin ci
npm --prefix plugin run build
```

== Installation ==

1. Upload the `ax402-for-woocommerce` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload.
2. Activate the plugin (WooCommerce required).
3. Set the store currency to USD.
4. Go to WooCommerce → Settings → Payments → Ax402 and enter your API key, pay-to wallet, and settlement tokens.

== Frequently Asked Questions ==

= Where do I get an API key? =

Sign in at https://ax402.io and create a scoped API key for your store.

= Why USD? =

Order totals are priced in USD and mapped to Ax402 settlement tokens. Non-USD store currencies are not supported in this release.

= Are the REST routes public? =

Yes, by design. Agent browse/buy routes are public storefront APIs. The fulfill callback is public so Ax402 can confirm payment; it is gated by WooCommerce order key plus a one-time fulfill token. Settlement selection requires a valid order key.

= Can I hide the Ax402 credit on the pay page? =

Yes. In gateway settings, leave “Show Ax402 credit on the pay page” unchecked (default).

== Changelog ==

= 0.1.0 =
* Initial release: gateway, fulfill upstream, agent API, pay page, Blocks support
