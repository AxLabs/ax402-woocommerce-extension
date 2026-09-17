=== Ax402 for WooCommerce ===
Contributors: axlabs
Tags: woocommerce, payments, crypto, usdc, x402
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept x402 / Ax402 wallet payments in WooCommerce. Buyers pay with supported stablecoins; Ax402 covers network fees.

== Description ==

Ax402 for WooCommerce adds a payment method that settles orders with Ax402 / x402. Shoppers pay from a wallet on the pay page. Agents can use the same payment URL via the Ax402 CLI or buyer SDKs.

Your catalog stays in USD. Settlement tokens come from your Ax402 platform config (for example USDC). Classic checkout and Cart / Checkout Blocks are supported. HPOS is supported.

You need an Ax402 merchant account and API key from https://ax402.io

= External services =

This plugin does not include analytics, advertising pixels, or other tracking. It contacts the services below only to accept Ax402 / x402 payments. Saving an Ax402 API key in WooCommerce settings is consent to use the Ax402 service. WalletConnect, Hedera Mirror Node, and public RPC calls run in the shopper’s browser when they choose to pay.

= Ax402 =

Ax402 (operated by AxLabs) creates payment endpoints, syncs settlement tokens, confirms settlements, and serves the payment gateway the shopper or agent pays. The browser and the WordPress server may call your assigned gateway host (`*.ax402.io`) under the same operator.

* When: configuring the gateway, placing an Ax402 order, loading the pay page, refreshing tokens, verifying payment, and (server-side) proxying or reconciling a payment when needed.
* Data sent: API key, pay-to wallet, order totals / amounts, order keys, selected settlement token, store origin for CORS, and settlement references returned by Ax402. No card data or wallet private keys are sent.
* Service: https://ax402.io
* Terms: https://ax402.io/terms
* Privacy: https://ax402.io/privacy
* Disclaimer: https://ax402.io/disclaimer

= Chain metadata (GitHub / ethereum-lists) =

When a settlement token does not already include a chain name, public RPC, or explorer URL, the plugin may fetch one public Ethereum chain JSON file from the GitHub Contents API at https://api.github.com/repos/ethereum-lists/chains/contents/_data/chains/eip155-{id}.json, cached for 24 hours per chain. The dataset is https://github.com/ethereum-lists/chains (MIT).

* When: resolving a display name, public RPC, or explorer for an EVM settlement network that lacks those fields on the Ax402 token.
* Data sent: HTTP GET with Accept and GitHub API version headers only. The path includes the numeric chain id. No personal data, API keys, or order data are sent.
* Service: https://api.github.com
* Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
* Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

= WalletConnect / Reown =

On the pay page, when a shopper selects Hedera settlement and the merchant has configured a WalletConnect project ID, the browser uses WalletConnect / Reown to discover and connect a Hedera wallet (for example HashPack). This includes WalletConnect relay traffic such as https://rpc.walletconnect.com/.

* When: only after the shopper starts Hedera wallet connect on the pay page.
* Data sent: project ID, dapp metadata (store name and URL), session data, and the wallet account id used for signing. Private keys stay in the wallet.
* Terms: https://reown.com/terms-of-service and https://walletconnect.com/terms
* Privacy: https://reown.com/privacy-policy and https://walletconnect.com/privacy
* Docs: https://docs.reown.com/

= Hedera Mirror Node =

On the pay page, the shopper's browser may query public Hedera Mirror Node REST endpoints (`mainnet-public.mirrornode.hedera.com` / `testnet.mirrornode.hedera.com`) to read token balances and network readiness.

* When: when a shopper pays with a Hedera settlement token.
* Data sent: Hedera account id and token id in public REST paths. No private keys are sent.
* Terms: https://hedera.com/terms
* Privacy: https://hedera.com/privacy

= Public EVM RPC endpoints =

The shopper’s browser may call public EVM JSON-RPC URLs taken from token metadata or ethereum-lists chain files (via the GitHub Contents API) for balance checks, chain switching, and payment signing with their wallet. No server-side private keys are sent. Exact RPC URLs vary by chain.

Wallet extensions (for example MetaMask) run locally in the shopper’s browser; private keys never leave the wallet.

UCP discovery JSON may include protocol spec and schema URLs (https://ucp.dev, https://x402.org/schemas/ucp-payment-handler.json, and https://github.com/AxLabs/ucp-x402-binding). Those are documentation identifiers. This plugin does not call them as APIs.

= Source code and build =

Minified frontend assets live in `build/`. Readable source, build tooling, and third-party license notes:

https://github.com/AxLabs/ax402-woocommerce-extension

See also `assets/THIRD_PARTY_LICENSES.md` in the plugin package.

```
npm --prefix plugin ci
npm --prefix plugin run build
# Optional lean EVM-only bundle (stubs Hedera): AX402_EVM_ONLY=1 npm --prefix plugin run build
```

The default build includes Hedera WalletConnect support (~2MB pay-page bundle).

== Installation ==

1. Upload the `ax402-for-woocommerce` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload.
2. Activate the plugin (WooCommerce required).
3. Set the store currency to USD.
4. Go to WooCommerce → Settings → Payments → Ax402 and enter your API key, EVM pay-to wallet, settlement tokens, and (if enabling Hedera tokens) a Hedera account id plus WalletConnect project ID.

== Frequently Asked Questions ==

= Where do I get an API key? =

Sign in at https://ax402.io, open API keys, give the key a name (for example “Ax402 for WooCommerce”), select All scopes, then Create API Key.

= Why USD? =

Order totals are priced in USD and mapped to Ax402 settlement tokens. Non-USD store currencies are not supported in this release.

= Are the REST routes public? =

Yes, by design. Agent browse/buy routes (`/wp-json/ax402/v1`) and UCP shopping routes (`/wp-json/ucp/v1`) are public storefront APIs for humans and buying agents. Each route sets permission_callback to __return_true. The fulfill callback is public so Ax402 can confirm payment; it is gated by WooCommerce order key plus a one-time fulfill token. UCP session, cart, and order URLs use the WooCommerce order key as the capability. Settlement selection requires a valid order key. Disable UCP in Ax402 settings if you do not want agent discovery.

= What is UCP? =

Universal Commerce Protocol support for buying agents (`/.well-known/ucp` and `/wp-json/ucp/v1`). On by default. Disable it on the Ax402 settings screen if you do not want agent discovery. The human pay page does not change. x402 payment on that surface follows https://github.com/AxLabs/ucp-x402-binding

= Can I hide the Ax402 credit on the pay page? =

Yes. In gateway settings, leave “Show Ax402 credit on the pay page” unchecked (default).

== Changelog ==

= 0.4.3 =
* WordPress.org review: load EVM chain metadata from the GitHub Contents API (ethereum-lists) with Terms and Privacy links
* No payment-flow changes

= 0.4.2 =
* WordPress.org review: enqueue pay-page shell and admin settings JS/CSS; document third-party services; keep public REST routes explicit
* No payment-flow changes

= 0.4.1 =
* Token sync errors show the HTTP status and a short response body instead of a generic "Request failed"
* Leaving Gateway slug blank no longer clears an auto-generated slug
* WordPress Plugin Check runs on CI and before GitHub Releases

= 0.4.0 =
* UCP for agents follows protocol 2026-08-25: current spec URLs, complete idempotency, optional payment Action; x402 hop stays External-URL
* Settings: readiness checklist, last settlements, and pay-to validation; API base URL is locked and the API key is masked
* UCP stays on by default; disable it in settings if you do not want agent discovery
* One mixed-token Ax402 endpoint for EVM and Hedera (`pay_to_addresses`) with cached USD FX rates
* Pay page: wait/retry states for slow wallet RPC, and confirming as soon as the wallet signs
* WooCommerce 11.1 tested-up-to; WordPress Plugin Check packaging fixes

= 0.3.0 =
* UCP complete no longer replays buyer PAYMENT-SIGNATURE. Agents pay resource.url with standard x402, then complete again (https://github.com/AxLabs/ucp-x402-binding)
* Fulfill ACKs HTTP 200 without marking the order paid until Ax402 has a matching settlement
* Status polls always complete unpaid orders from a matching Ax402 settlement

= 0.2.0 =
* Optional UCP for buying agents: `/.well-known/ucp` plus REST and MCP shopping (off by default)
* Catalog search/lookup and checkout with WooCommerce tax/shipping; x402 HTTP 402 at complete
* MCP `complete_checkout` stays JSON-RPC 200 with PaymentRequired on `structuredContent` (nested `payment_required` kept for UCP clients)
* Persist settlement-token selection across checkout update/complete; each token is its own x402 resource
* `requires_escalation` plus `continue_url` when Woo has no shipping rates; JSON body `payment.payment_signature` for large JWTs (Hedera)
* Human pay page confirmation state; legacy `/wp-json/ax402/v1` agent REST unchanged

= 0.1.0 =
* Initial release: Ax402 / x402 WooCommerce payment gateway
* Human pay page with wallet connect (EVM + optional Hedera WalletConnect)
* Multi-token settlement from Ax402 platform config; catalog stays USD
* Dual store APIs for EVM and Hedera settlement families
* Fulfill upstream, agent REST browse/buy, Cart & Checkout Blocks, HPOS
* Opt-in Ax402 credit on the pay page; uninstall cleanup for plugin options
