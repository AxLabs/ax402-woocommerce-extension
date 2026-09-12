# Architecture

Product framing and ownership boundaries: [context.md](context.md).  
Local env and seed: [local-development.md](local-development.md).  
Full E2E (tunnel + pay): [e2e.md](e2e.md).  
UCP for agents: [ucp.md](ucp.md). x402 on UCP: [ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding).

## Components

1. **WooCommerce payment gateway (`ax402`)** — creates a pending order and prepares an Ax402 endpoint priced to the order total.
2. **Ax402 control plane** — `POST /apis`, endpoint CRUD at `https://api.ax402.io` (or staging).
3. **Ax402 gateway** — buyer-facing paid URL that issues HTTP 402, verifies payment, proxies to Woo fulfill.
4. **Woo fulfill REST** — `GET /wp-json/ax402/v1/fulfill/{order_key}/{fulfill_token}` ACKs the x402 resource (HTTP 200). It calls `payment_complete()` only when Ax402 already has a matching settlement.
5. **Human pay page** — storefront page mounting `@ax402/react-paywall` against the gateway URL.
6. **Agent REST** — catalog + create order + status (+ settlement lock) under `/wp-json/ax402/v1/*`.
7. **UCP for agents (opt-in)** — `/.well-known/ucp` + `/wp-json/ucp/v1` catalog, cart, checkout, order (REST) and `/wp-json/ucp/v1/mcp` (MCP JSON-RPC). Protocol **2026-04-08**. REST 402 at complete; MCP `complete_checkout` carries PaymentRequired on `structuredContent`. Agents pay `resource.url` (Ax402 gateway) then complete again; the plugin does **not** proxy signatures. Human pay page unchanged. See [ucp.md](ucp.md) and [ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding).
8. **Settlement reconcile** — status polls mark the order paid from a matching control-plane settlement. Required when Ax402 GETs fulfill **before** writing the ledger (Hedera); also covers missing fulfill (tunnels).

## Payment flow (human)

```text
Checkout (USD)
    │
    ▼
prepare order endpoint
  · one endpoint, multi-token accepts[] (FX from control plane / 1:1 stables)
  · path = /wp-json/ax402/v1/fulfill/{order_key}/{fulfill_token}
  · upstream_base_url = store public origin (home_url)
  · if upstream host is ngrok*: endpoint upstream_auth
      { type: header, header: ngrok-skip-browser-warning, value: 1 }
    │
    ▼
Pay page (store origin)
  · shopper picks settlement token (USDC / ZCHF / …)
  · POST /wp-json/ax402/v1/orders/{key}/settlement
      → remember preference (shared gateway_url; paywall policy selects the accept)
  · PaywallGate fetches gateway URL directly (CORS)
    │
    ├─ unpaid GET  → 402 Payment-Required
    ├─ wallet pays → PAYMENT-SIGNATURE retry
    └─ gateway GET {upstream_base_url}{path}  (x402 resource = fulfill path)
            │
            ▼
        Woo fulfill (valid token)
          matching settlement → payment_complete()
          else → HTTP 200 ACK, order stays unpaid
            │
            ▼
        gateway submits on-chain + writes settlement
            │
            ▼
        pay page polls GET /wp-json/ax402/v1/orders/{key}
          reconcile → payment_complete() when the row exists
```

Agents follow the same gateway GET fulfill → settle → reconcile path; they call the gateway URL from a buyer SDK instead of the pay page.

## One API, one endpoint

Payment prep creates **one temporary Ax402 endpoint** with every priced token in `accepts[]`. Example: USDC on Base, USDC on Hedera, and XGAS on Neo X → one endpoint, three accepts, one fulfill path.

Before `PaywallGate` runs, the pay page records the shopper’s token via:

`POST /wp-json/ax402/v1/orders/{order_key}/settlement` `{ "tokenId": "…" }`

That updates order meta and paywall policy (`preferredNetworks` / `preferredAssets`). It does not rewrite the control-plane endpoint. The gateway 402 already lists every accept with the correct atomic amount.

## Dual pay-to, one API

- **EVM:** one `pay_to_address` for all `eip155:*` networks (fallback recipient).
- **Hedera:** `pay_to_hedera_account_id` (`0.0.x`) in `pay_to_addresses` keyed by CAIP-2 network (e.g. `"hedera:mainnet"`). Editable only when any Hedera settlement token is enabled. WalletConnect project id is required for Hedera shopper wallets.

A single store API is scoped with `accept_all_tokens=false` and the merchant’s `accepted_token_ids` (EVM and Hedera together). Mixing families without `pay_to_addresses` still fails (`hedera:…: payTo: invalid Hedera account id`). Legacy `hedera_api_id` values are kept for in-flight order cleanup only.

Hedera payment requirements include `extra.feePayer` from facilitator `GET /supported-networks` (or `signers["hedera:*"]`). The shopper only **signs** a partially-signed `TransferTransaction`; the facilitator co-signs, pays network fees, and submits.

## Upstream fulfill & ngrok

The x402 resource URL uses the fulfill path. The gateway GETs:

`{api.upstream_base_url}` + `{endpoint.path_pattern}`

That hop may run **before** Ax402 submits the chain transfer and writes the settlement (observed on Hedera). Woo must return HTTP 200 so settle proceeds. A 409 “not settled” aborts that path (fee-only transfer, no USDC).

`ensure_api()` keeps the store API `upstream_base_url` aligned with `home_url()` (the public tunnel in E2E).

**Free ngrok** (`*.ngrok-free.dev`) returns interstitial HTML (`ERR_NGROK_6024`) to non-browser clients unless the request includes `ngrok-skip-browser-warning`. When the store origin host contains `ngrok`, endpoint create/update sets:

```json
{
  "upstream_auth": {
    "type": "header",
    "header": "ngrok-skip-browser-warning",
    "value": "1"
  }
}
```

The gateway injects that header on the upstream hop. Without it, settle can succeed while Woo never sees fulfill (pay page stays on “Confirming payment…”). When fulfill finds a matching settlement it adds: **Ax402 payment verified via fulfill upstream.**

## Settlement reconcile

Pay-page, agent, and UCP status polls always ask the control plane for settlements and, if a matching on-chain settlement exists for the order’s `endpoint_id` (or any leftover per-token ids on older orders), mark the order paid. That is the usual complete path when fulfill ACKed before the ledger row existed. It also covers missing fulfill (tunnels).

Matching is by **endpoint_id** (authoritative). Amount comparison is best-effort only — FX / decimal differences must not block reconcile.

Reconciled orders note: **Gateway upstream fulfill was missing.**

## Amount / FX notes

- Catalog total is USD; non-stable tokens use control-plane `GET /exchange-rates?quote=usd&date=YYYY-MM-DD` (weekdays: today → yesterday if a weekday → closest previous business day; weekends skip Sat/Sun and use Friday). Results are cached 5 minutes; checkout and the pay page reuse that cache.
- When truncating to the gateway’s max fraction digits, amounts **ceil** (round up) so the charged atomic is never below the converted value.
- Primary meta `_ax402_amount_atomic` follows the selected / primary token (not always 6-decimal USDC).

## Trust model (v0)

Fulfill ACKs HTTP 200 when `order_key` + one-time `fulfill_token` are valid. It calls `payment_complete()` only when Ax402 has a matching settlement for the order’s `endpoint_id`. The path token alone is not enough: the gateway URL uses the same path, so a reverse-proxied unpaid GET can present the token without a ledger row — that request must not mark the order paid (and must not 409, or Ax402 will not settle).

## Browser CORS

The human pay page runs on the store origin and calls the Ax402 gateway **directly**. On API create / settings save / pay-page load, the plugin pushes the store origin(s) to `PUT/POST /apis/{id}/cors` so the gateway allows browser `fetch()`.

The same-origin `pay-proxy` route remains available as a fallback for older gateways that have not redeployed per-API CORS yet, but the pay page prefers the direct gateway URL.

Agents still pay the real gateway URL directly.

## Currency / networks

- Store currency: **USD**; settlement via merchant-enabled platform tokens (stables 1:1).
- Dev network: Base Sepolia (`eip155:845320402`) from `/config/platform`.
- Prod network: Base mainnet (`eip155:8453`).
