# Architecture

Product framing and ownership boundaries: [context.md](context.md).  
Local env and seed: [local-development.md](local-development.md).

## Components

1. **WooCommerce payment gateway (`ax402`)** — creates a pending order and prepares an Ax402 endpoint priced to the order total.
2. **Ax402 control plane** — `POST /apis`, endpoint CRUD at `https://api.ax402.io`.
3. **Ax402 gateway** — buyer-facing paid URL that issues HTTP 402, verifies payment, proxies to Woo fulfill.
4. **Woo fulfill REST** — `GET /wp-json/ax402/v1/fulfill/{order_key}/{fulfill_token}` marks the order paid.
5. **Human pay page** — storefront page mounting `@ax402/react-paywall` against the gateway URL.
6. **Agent REST** — catalog + create order + status under `/wp-json/ax402/v1/*`.

## Trust model (v0)

Fulfill accepts requests that present a valid `order_key` and one-time `fulfill_token` stored on the order. The token is only embedded in the Ax402 endpoint path configured at payment prep time.

## Browser CORS

The human pay page runs on the store origin. Direct `fetch()` to `*.ax402.io` is often blocked by CORS. Humans therefore call same-origin `GET/POST /wp-json/ax402/v1/pay-proxy/{order_key}`, and WordPress relays to the real gateway URL server-side. Agents still pay the real gateway URL directly.

## Currency / networks

- Store currency: **USD** (USDC 1:1).
- Dev network: Base Sepolia (`eip155:845320402`) from `/config/platform`.
- Prod network: Base mainnet (`eip155:8453`).
