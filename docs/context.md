# Extension context

## What this is

**Ax402 for WooCommerce** is a **payment provider plugin**. It adds an `ax402` payment method so a WooCommerce store can accept **x402 / Ax402** stablecoin payments (today: **USDC**, priced 1:1 with a **USD** catalog).

It supports two buyer types on the same order lifecycle:

| Buyer | How they pay |
|---|---|
| **Human** | Checkout → store pay page → wallet (e.g. MetaMask) via `@ax402/react-paywall` |
| **Agent** | REST create-order → pay the Ax402 gateway URL with a buyer SDK / CLI |

Hosted control plane: `https://api.ax402.io`.

## What this is not

This plugin is **not** a full storefront product. It does not own:

- Product catalog UX (beyond optional local demo seed)
- Cart / checkout theme design
- Customer accounts, CRM, or a custom order inbox
- Multi-currency browsing / FX switchers

Those belong to **WooCommerce** (and optional third-party plugins). Merchants manage catalog, customers, and orders in the normal WooCommerce admin.

## Separation of concerns

```text
┌─────────────────────────────────────────────────────────┐
│  WooCommerce                                            │
│  products · cart · checkout · customers · orders        │
└───────────────────────────┬─────────────────────────────┘
                            │ payment method: ax402
┌───────────────────────────▼─────────────────────────────┐
│  Ax402 WooCommerce plugin                               │
│  gateway settings · endpoint prep · pay page · fulfill  │
└───────────────────────────┬─────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────┐
│  Ax402 platform                                         │
│  control plane (apis/endpoints) · buyer gateway (402)   │
└─────────────────────────────────────────────────────────┘
```

| Concern | Owner |
|---|---|
| Catalog prices, customers, order list | WooCommerce |
| API key, pay-to wallet, network, enable gateway | Plugin → **WooCommerce → Settings → Payments → Ax402** |
| HTTP 402 challenge, payment verify, proxy to fulfill | Ax402 gateway |
| Mark order paid after verified payment | Plugin fulfill REST |

## Currency model (v0)

- Store currency: **USD** (required).
- Settlement: **USDC** on Base (Sepolia for dev, mainnet for production), **1:1** with the order total.
- Sub-cent catalog prices are supported when decimals are raised (plugin helps when Ax402 is enabled).
- Multi-currency catalog switching is **out of scope** for this plugin. Use WooCommerce (one base currency) or a dedicated multi-currency plugin if needed.

## Trust model (v0)

1. Checkout selects Ax402 → plugin creates/updates a per-order Ax402 endpoint priced to the total.
2. Fulfill URL embeds `order_key` + a one-time `fulfill_token` stored on the order.
3. After the gateway verifies payment, it calls fulfill; WooCommerce runs `payment_complete()`.
4. Humans use a **same-origin pay proxy** so the browser is not blocked by CORS on `*.ax402.io`. Agents call the real gateway URL directly.

## Where merchants look day to day

| Task | Where |
|---|---|
| Configure Ax402 | **WooCommerce → Settings → Payments → Ax402** |
| See orders, email, totals, status | **WooCommerce → Orders** |
| Shop / checkout | Normal WooCommerce storefront |
| Local demo products | Seeded by `bin/seed-wp-env.sh` (dev only) |

## Related docs

- [Local development & seeding](local-development.md) — start wp-env, seed, tunnel, first payment
- [E2E environment](e2e.md) — ready-to-go seed + ngrok + programmatic / MetaMask pay (`npm run env:e2e`)
- [Architecture](architecture.md) — components and flows
- [Merchant setup](merchant-setup.md) — production-oriented checklist
- [Testing](testing.md) — unit, live control plane, E2E commands
