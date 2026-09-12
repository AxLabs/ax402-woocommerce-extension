# Merchant setup

For local wp-env and demo seeding, use [local-development.md](local-development.md).  
For what this plugin owns vs WooCommerce, see [context.md](context.md).

1. Create an Ax402 seller account at [ax402.io](https://ax402.io) and verify email.
2. Create an API key with `apiManager` scopes.
3. In WooCommerce → Settings → Payments → **Ax402 (x402)**:
   - Enable the method
   - Set API base URL (`https://api.ax402.io`)
   - Paste API key
   - Set **EVM pay-to** wallet (one address for all EVM networks)
   - Enable settlement tokens from Ax402 platform config. If any **Hedera** token is enabled, unlock and set **Hedera pay-to** account id (`0.0.x`) plus a **WalletConnect project ID** for shopper wallets
   - Choose **default network seed** (Base Sepolia for testing, Base mainnet for production) — used for onboarding / default USDC when no tokens are selected yet
   - Sync and enable **settlement tokens** (stablecoins settle 1:1 with the USD catalog total; other tokens use dated `/exchange-rates`; checkout creates one temporary Ax402 endpoint with every priced token in `accepts[]`)
4. Ensure store currency is **USD** (catalog). Shoppers still pay in the settlement token they pick on the pay page.
5. For sub-cent catalog prices (e.g. `$0.001`), set **WooCommerce → Settings → General → Number of decimals** to at least **4** (USD defaults to 2, which displays those as `$0.00`). When Ax402 is enabled, the plugin raises display decimals to at least 4 and trims trailing zeros (so `$0.001` / `$0.10` render correctly).
6. For local testing with real gateway upstream, expose the shop with a public tunnel and ensure Ax402 API `upstream_base_url` matches that public origin (re-onboard / update API if needed). Free ngrok requires the endpoint `upstream_auth` skip header — the plugin sets it automatically when `home_url` is an ngrok host (details in [architecture.md](architecture.md)).
7. **Settlement reconcile** (default on): status polls mark orders paid from Ax402 settlements. Needed because the gateway may call fulfill before the ledger row exists; also covers missing fulfill.
8. If endpoint creation fails for **Base Sepolia** (`eip155:845320402`), confirm the seller account has that payment asset enabled in Ax402 (or temporarily use Base mainnet for smoke tests).

## Agent buy

```http
GET /wp-json/ax402/v1/products
POST /wp-json/ax402/v1/orders
  { "line_items": [{ "product_id": 12, "quantity": 1 }] }
→ { "payment_url": "https://{slug}.dev.x.ax402.io/wp-json/ax402/v1/fulfill/...", ... }

# then
ax402 pay url --url "$payment_url"
GET /wp-json/ax402/v1/orders/{order_key}
```

Optional **UCP for agents** is off by default. Enable it on the Ax402 settings screen. Flow and 402-at-complete: [ucp.md](ucp.md). x402 binding: [ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding).
