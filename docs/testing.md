# Testing

Environment setup: [local-development.md](local-development.md).  
**Full E2E (seed + tunnel + pay):** [e2e.md](e2e.md).

## Unit (default CI)

```bash
npm run test:unit
npm run test:js
```

PHP unit tests run via Docker Composer if local PHP is unavailable.

## WordPress Plugin Check

[Plugin Check](https://wordpress.org/plugins/plugin-check/) is the same tool WordPress.org uses before a directory review. **Errors** fail CI and block the GitHub Release; **warnings** (for example known slow-query PHPCS hints) are reported and do not fail.

```bash
npm run env:start        # once
npm run plugin-check     # wp-env mount + production excludes
```

CI (`.github/workflows/ci.yml`, GitHub-hosted) and the Release workflow package the zip first, then run [`wordpress/plugin-check-action`](https://github.com/WordPress/plugin-check-action) on that tree. Local `plugin-check` needs a running wp-env and is an approximation of that zip check.

UCP protocol rules (cents, discovery shape, leak scanner, asset matching, MCP PaymentRequired on `structuredContent` / `_meta["x402/payment"]`) live in `tests/php/Unit/Ucp*.php`. Live UCP settlement is `npm run test:e2e-ucp`. Shopify CLI smoke (no wallet) is `npm run test:e2e-ucp-cli`. See [ucp.md](ucp.md) and [ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding).

## Live control plane

```bash
export AX402_API_KEY=ax402_live_...
export AX402_PAY_TO_ADDRESS=0x...
export AX402_PAY_TO_HEDERA_ACCOUNT_ID=0.0.x   # optional; enables mixed-family API check
npm run test:live-cp
```

Creates and deletes a temporary API + endpoint against `https://api.ax402.io`.
Also covers mixed EVM+Hedera `pay_to_addresses` (when `AX402_PAY_TO_HEDERA_ACCOUNT_ID` is set) and a USDC+ZCHF multi-accept 402 amount check.
The CRUD smoke uses Base mainnet USDC accepts when the seller account cannot yet price `eip155:845320402` assets.

## E2E payments

Prefer the dedicated guide: **[e2e.md](e2e.md)**.

Quick path once `.env` is filled and `wp-env` is up:

```bash
npm run env:e2e          # seed + readiness (+ WP_BASE_URL sync)
# with tunnel running and WP_BASE_URL set:
npm run test:e2e-pay     # programmatic agent pay
npm run test:e2e-ucp     # UCP agent (on after seed; AX402_UCP_ENABLED=no to disable)
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
