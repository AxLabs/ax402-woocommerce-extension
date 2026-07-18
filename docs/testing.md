# Testing

Environment setup: [local-development.md](local-development.md).  
**Full E2E (seed + tunnel + pay):** [e2e.md](e2e.md).

## Unit (default CI)

```bash
npm run test:unit
npm run test:js
```

PHP unit tests run via Docker Composer if local PHP is unavailable.

## Live control plane

```bash
export AX402_API_KEY=ax402_live_...
export AX402_PAY_TO_ADDRESS=0x...
npm run test:live-cp
```

Creates and deletes a temporary API + endpoint against `https://api.ax402.io`.
The CRUD smoke uses Base mainnet USDC accepts when the seller account cannot yet price `eip155:845320402` assets.

## E2E payments

Prefer the dedicated guide: **[e2e.md](e2e.md)**.

Quick path once `.env` is filled and `wp-env` is up:

```bash
npm run env:e2e          # seed + readiness (+ WP_BASE_URL sync)
# with tunnel running and WP_BASE_URL set:
npm run test:e2e-pay     # programmatic agent pay
```

### Playwright UI

```bash
export E2E_ORDER_KEY=wc_order_...
npm run test:e2e-ui
```

Asserts the pay page shell. MetaMask connect/sign remains a **manual** checklist.

## Manual MetaMask checklist

1. Configure gateway in Woo → Settings → Payments → Ax402 (or rely on seed from `.env`).
2. Place an order with Ax402 (use public `WP_BASE_URL` for live settle).
3. On pay page, connect MetaMask on the network matching `AX402_NETWORK`.
4. Confirm USDC payment.
5. Confirm redirect to order-received and order status Processing/Completed.
