=== Ax402 for WooCommerce ===
Contributors: axlabs
Tags: woocommerce, x402, payments, crypto, agents
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Agentic commerce for WooCommerce: UCP catalog-to-checkout and x402 stablecoin payments to your own wallets.

== Description ==

[Ax402 for WooCommerce](https://wordpress.org/plugins/ax402-for-woocommerce/) is the agentic commerce extension for WooCommerce. It is not only a payment method.

Buying agents can **discover and search your catalog, create a cart, check out, and pay** — using [Universal Commerce Protocol (UCP)](https://ucp.dev) and [x402](https://x402.org). Human shoppers get the same x402 wallet payment on the store pay page. You keep WooCommerce for products, customers, and orders.

The x402 hop on the UCP surface follows [AxLabs/ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding).

= Your wallets. No take rate on sales. =

You set the payout wallets (one EVM address for all EVM networks, plus a Hedera account if you enable Hedera tokens). Settlements go **to those wallets**. There is no payment intermediary that holds the funds.

[Ax402](https://ax402.io) facilitates settlement: payment endpoints, the token list, and confirmation. **Ax402 does not take a percentage of products sold.**

Catalog prices stay in **USD**. Shoppers and agents pay in the settlement tokens you enable from your Ax402 platform config (for example USDC). Ax402 covers network fees for those payments.

Works with classic checkout, Cart / Checkout Blocks, and HPOS.

You need an [Ax402 merchant account](https://ax402.io) and API key.

= For merchants =

* WooCommerce payment method `ax402` with a readiness checklist
* Settlement tokens synced from [Ax402](https://ax402.io) (stablecoins 1:1 with the USD total; other tokens use Ax402 FX)
* Human pay page: wallet connect, network switch, balance checks
* Orders stay in **WooCommerce → Orders**
* Optional “Powered by Ax402” on the pay page (off by default)

= For buying agents =

* [UCP](https://ucp.dev) discovery at `/.well-known/ucp` and shopping at `/wp-json/ucp/v1` (catalog, cart, checkout, order, MCP)
* x402 payment required at checkout complete — agents pay `resource.url`, then complete again
* Legacy agent REST at `/wp-json/ax402/v1` (products, create order, status)

UCP is **on by default**. Turn it off in Ax402 settings if you do not want agent discovery. That does not change the human pay page.

= External services =

This plugin does not include analytics, advertising pixels, or other tracking. It contacts the services below only to run Ax402 / x402 payments (and Hedera wallet connect when you enable it). Saving an Ax402 API key in WooCommerce settings is consent to use Ax402. WalletConnect, Hedera Mirror Node, and public RPC calls run in the **shopper’s browser** when they choose to pay.

= Ax402 =

[Ax402](https://ax402.io) (operated by [AxLabs](https://axlabs.com)) creates payment endpoints, syncs settlement tokens, confirms settlements, and serves the gateway the shopper or agent pays. The browser and WordPress may call your assigned gateway host (`*.ax402.io`) under the same operator.

* When: configuring the gateway, placing an Ax402 order, loading the pay page, refreshing tokens, verifying payment, and (server-side) proxying or reconciling a payment when needed
* Data sent: API key, pay-to wallet, order totals / amounts, order keys, selected settlement token, store origin for CORS, settlement references. No card data or wallet private keys
* [Service](https://ax402.io) · [Terms](https://ax402.io/terms) · [Privacy](https://ax402.io/privacy) · [Disclaimer](https://ax402.io/disclaimer)

= Chain metadata (GitHub / ethereum-lists) =

When a settlement token does not already include a chain name, public RPC, or explorer URL, the plugin may fetch one public Ethereum chain JSON file from the GitHub Contents API (`eip155-{id}.json`), cached 24 hours per chain. Dataset: [ethereum-lists/chains](https://github.com/ethereum-lists/chains) (MIT).

* When: resolving a display name, public RPC, or explorer for an EVM network that lacks those fields on the Ax402 token
* Data sent: HTTP GET with Accept and GitHub API version headers. The path includes the numeric chain id. No personal data, API keys, or order data
* [Service](https://api.github.com) · [Terms](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service) · [Privacy](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement)

= WalletConnect / Reown =

On the pay page, when a shopper selects Hedera settlement and you have set a WalletConnect project ID, the browser uses [WalletConnect](https://walletconnect.com) / [Reown](https://reown.com) to connect a Hedera wallet (for example HashPack), including relay traffic such as `https://rpc.walletconnect.com/`.

* When: only after the shopper starts Hedera wallet connect on the pay page
* Data sent: project ID, dapp metadata (store name and URL), session data, and the wallet account id used for signing. Private keys stay in the wallet
* [Reown terms](https://reown.com/terms-of-service) · [WalletConnect terms](https://walletconnect.com/terms)
* [Reown privacy](https://reown.com/privacy-policy) · [WalletConnect privacy](https://walletconnect.com/privacy)
* [Docs](https://docs.reown.com/)

= Hedera Mirror Node =

The shopper’s browser may query public Hedera Mirror Node REST (`mainnet-public.mirrornode.hedera.com` / `testnet.mirrornode.hedera.com`) for token balances and network readiness.

* When: when a shopper pays with a Hedera settlement token
* Data sent: Hedera account id and token id in public REST paths. No private keys
* [Terms](https://hedera.com/terms) · [Privacy](https://hedera.com/privacy)

= Public EVM RPC endpoints =

The shopper’s browser may call public EVM JSON-RPC URLs from token metadata or ethereum-lists chain files (via the GitHub Contents API) for balance checks, chain switching, and payment signing. Exact RPC URLs vary by chain. Wallet extensions (for example MetaMask) run locally; private keys never leave the wallet.

UCP discovery JSON may include protocol spec URLs ([ucp.dev](https://ucp.dev), [x402 UCP payment handler schema](https://x402.org/schemas/ucp-payment-handler.json), [ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding)). Those are documentation identifiers, not APIs this plugin calls.

== Installation ==

1. Upload the `ax402-for-woocommerce` folder to `/wp-content/plugins/`, or install the zip via **Plugins → Add New → Upload**.
2. Activate the plugin (WooCommerce required).
3. Set the store currency to **USD**.
4. Go to **WooCommerce → Settings → Payments → Ax402**. Enter your [Ax402](https://ax402.io) API key, EVM pay-to wallet, settlement tokens, and (if you enable Hedera tokens) a Hedera account id plus WalletConnect project ID.

This plugin is also listed in the [WordPress Plugin Directory](https://wordpress.org/plugins/ax402-for-woocommerce/). In WP Admin you can go to **Plugins → Add New**, search for **Ax402 for WooCommerce**, and install from there.

== Frequently Asked Questions ==

= What does this plugin do? =

It turns a WooCommerce store into an agentic commerce endpoint: [UCP](https://ucp.dev) for catalog → cart → checkout, and [x402](https://x402.org) stablecoin payment for agents and humans. WooCommerce still owns products, customers, and the order list.

= Is this only a crypto checkout button? =

No. Payment is one part. The package is discovery, cart, checkout, and settlement on open standards, plus a human pay page.

= Do I need an Ax402 account? =

Yes. Create a merchant account at [ax402.io](https://ax402.io), then paste an API key into the gateway settings.

= Does Ax402 take a percentage of what I sell? =

No. Ax402 does **not** take a cut of products sold. You configure your own wallets; settlements go there. Ax402 facilitates the payment session (endpoints, tokens, confirmation). Platform account/plan terms still apply on [ax402.io](https://ax402.io) — that is not a take rate on the WooCommerce order.

= Where does the money go? =

To the **pay-to wallets you set** in WooCommerce (EVM address; Hedera account id if you enable Hedera tokens). Ax402 is not a merchant-of-record wallet that sits in the middle of the payout.

= What is x402? =

[x402](https://x402.org) is the HTTP 402 Payment Required standard for on-chain (typically stablecoin) settlement. Shoppers pay from a wallet; agents pay the payment URL with an x402 client.

= What is UCP? =

[Universal Commerce Protocol](https://ucp.dev) is how buying agents find the store, browse the catalog, build a cart, and check out. This plugin exposes `/.well-known/ucp` and `/wp-json/ucp/v1`. x402 on that surface follows [AxLabs/ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding). On by default; disable it in Ax402 settings if you do not want agent discovery.

= Can human customers pay too? =

Yes. At checkout they choose Ax402, then pay on the store pay page with a wallet (EVM; Hedera via WalletConnect when you configure it).

= Why USD? =

Order totals are priced in USD and mapped to Ax402 settlement tokens. Non-USD store currencies are not supported in this release.

= How do I get an API key? =

Sign in at [ax402.io](https://ax402.io), open **API keys**, name the key (for example “Ax402 for WooCommerce”), select **All scopes**, then **Create API Key**. Paste it into WooCommerce → Settings → Payments → Ax402.

= Which tokens and networks can I accept? =

Whatever your Ax402 platform config exposes. You multi-select tokens in gateway settings (for example USDC on Base). Customers pick one on the pay page. Stablecoins settle 1:1 with the USD total; other tokens need a resolvable FX rate from Ax402.

= Are the REST routes public? =

Yes, by design. Agent browse/buy (`/wp-json/ax402/v1`) and UCP (`/wp-json/ucp/v1`) are public storefront APIs. The fulfill callback is public so Ax402 can confirm payment; it is gated by WooCommerce order key plus a one-time fulfill token. UCP session, cart, and order URLs use the order key as the capability. Disable UCP in settings if you do not want agent discovery.

= Can I hide the Ax402 credit on the pay page? =

Yes. Leave **Show Ax402 credit on the pay page** unchecked (default).

= Does it support Cart/Checkout Blocks and HPOS? =

Yes.

= Where do I get help or report a bug? =

Open an issue on GitHub: [AxLabs/ax402-woocommerce-extension](https://github.com/AxLabs/ax402-woocommerce-extension/issues). Do not use WordPress.org SVN for development or issue tracking.

== Screenshots ==

1. Gateway settings: readiness checklist, API key, and payout wallets you control.
2. Settlement tokens synced from Ax402. Enable the assets you want; customers pick one at pay time.
3. Recent settlements, UCP for buying agents, and optional pay-page credit.
4. Create an Ax402 API key (All scopes) in the merchant dashboard.
5. Ax402 merchant platform at ax402.io — account, APIs, and income.

== Changelog ==

Canonical release notes live on GitHub. This section is a pointer so WordPress.org stays in sync without a second changelog.

= 0.4.3 =
[GitHub release v0.4.3](https://github.com/AxLabs/ax402-woocommerce-extension/releases/tag/v0.4.3)

= 0.4.2 =
[GitHub release v0.4.2](https://github.com/AxLabs/ax402-woocommerce-extension/releases/tag/v0.4.2)

= 0.4.1 =
[GitHub release v0.4.1](https://github.com/AxLabs/ax402-woocommerce-extension/releases/tag/v0.4.1)

= 0.4.0 =
[GitHub release v0.4.0](https://github.com/AxLabs/ax402-woocommerce-extension/releases/tag/v0.4.0)

= 0.3.0 =
[GitHub release v0.3.0](https://github.com/AxLabs/ax402-woocommerce-extension/releases/tag/v0.3.0)

= 0.2.0 =
[GitHub release v0.2.0](https://github.com/AxLabs/ax402-woocommerce-extension/releases/tag/v0.2.0)

= 0.1.0 =
[GitHub release v0.1.0](https://github.com/AxLabs/ax402-woocommerce-extension/releases/tag/v0.1.0)

== Development ==

Active development, pull requests, and issue tracking are on GitHub, not WordPress.org SVN:

[https://github.com/AxLabs/ax402-woocommerce-extension](https://github.com/AxLabs/ax402-woocommerce-extension)

Related AxLabs repositories:

* [AxLabs/ax402-woocommerce-extension](https://github.com/AxLabs/ax402-woocommerce-extension) — this plugin
* [AxLabs/ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding) — UCP × x402 payment binding
* [GitHub Releases](https://github.com/AxLabs/ax402-woocommerce-extension/releases) — full changelog per version

WordPress.org SVN is a **publish snapshot** of tagged releases for the Plugin Directory.

Minified frontend assets live in `build/`. Readable source, build tooling, and third-party license notes are in the GitHub repo. See also `assets/THIRD_PARTY_LICENSES.md` in the plugin package.

```
npm --prefix plugin ci
npm --prefix plugin run build
# Optional lean EVM-only bundle (stubs Hedera): AX402_EVM_ONLY=1 npm --prefix plugin run build
```

The default build includes Hedera WalletConnect support (~2MB pay-page bundle).
