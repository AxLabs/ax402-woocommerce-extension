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
  · multi-token accepts[] (FX from control plane / 1:1 stables)
  · path = /wp-json/ax402/v1/fulfill/{order_key}/{fulfill_token}
  · upstream_base_url = store public origin (home_url)
  · if upstream host is ngrok*: endpoint upstream_auth
      { type: header, header: ngrok-skip-browser-warning, value: 1 }
    │
    ▼
Pay page (store origin)
  · shopper picks settlement token (USDC / ZCHF / …)
  · POST /wp-json/ax402/v1/orders/{key}/settlement
      → lock endpoint to that single accept (FX amount)
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

## Per-token endpoints

Payment prep creates **one temporary Ax402 endpoint per enabled settlement token** (single `accept` each). Example: USDC on Base, USDC on Hedera, and XGAS on Neo X → three endpoints with distinct fulfill path suffixes.

Before `PaywallGate` runs, the pay page selects the shopper’s token via:

`POST /wp-json/ax402/v1/orders/{order_key}/settlement` `{ "tokenId": "…" }`

That returns the pre-created `gateway_url` / `endpoint_id` for that token (no multi-accept rewrite). This avoids gateway bugs when co-listing currencies on one endpoint.

## Dual pay-to & dual APIs

- **EVM:** one `pay_to_address` for all `eip155:*` networks on the store’s EVM Ax402 API (`api_id` / `gateway_host`).
- **Hedera:** `pay_to_hedera_account_id` (`0.0.x`) on a **separate** Hedera Ax402 API (`hedera_api_id` / `hedera_gateway_host`). Editable only when any Hedera settlement token is enabled. WalletConnect project id is required for Hedera shopper wallets.

The control plane rejects mixing `eip155` and `hedera` payment tokens on one API, so the plugin maintains **two store APIs** and creates per-token temporary endpoints on the matching family. Each API is scoped with `accept_all_tokens=false` and family-only `accepted_token_ids`.

Hedera payment requirements include `extra.feePayer` from facilitator `GET /supported-networks` (or `signers["hedera:*"]`). The shopper only **signs** a partially-signed `TransferTransaction`; the facilitator co-signs, pays network fees, and submits.

## Upstream fulfill & ngrok

The x402 resource URL uses the fulfill path. The gateway GETs:

`{api.upstream_base_url}` + `{endpoint.path_pattern}`

That hop may run **before** Ax402 submits the chain transfer and writes the settlement (observed on Hedera). Woo must return HTTP 200 so settle proceeds. A 409 “not settled” aborts that path (fee-only transfer, no USDC).

`ensure_api()` / `ensure_hedera_api()` keep each family’s `upstream_base_url` aligned with `home_url()` (the public tunnel in E2E).

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

Pay-page, agent, and UCP status polls always ask the control plane for settlements and, if a matching on-chain settlement exists for **any** of the order’s per-token `endpoint_id`s, mark the order paid. That is the usual complete path when fulfill ACKed before the ledger row existed. It also covers missing fulfill (tunnels).

Matching is by **endpoint_id** (authoritative). Amount comparison is best-effort only — FX / decimal differences must not block reconcile.

Reconciled orders note: **Gateway upstream fulfill was missing.**

## Amount / FX notes

- Catalog total is USD; non-stable tokens use control-plane `GET /exchange-rates?quote=usd&date=YYYY-MM-DD` (today → yesterday → closest previous business day on empty/error).
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
