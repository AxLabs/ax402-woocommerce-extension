# Testing

Environment setup: [local-development.md](local-development.md).

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

## Programmatic pay E2E

Requires a **public** `WP_BASE_URL` (Cloudflare Tunnel / ngrok / staging). Local `localhost` is not reachable from the Ax402 gateway.

```bash
export WP_BASE_URL=https://your-tunnel.example
export AX402_EVM_PRIVATE_KEY=0x...
npm run test:e2e-pay
```

## Playwright UI

```bash
export E2E_ORDER_KEY=wc_order_...
npm run test:e2e-ui
```

Asserts the pay page shell. MetaMask connect/sign remains a **manual** checklist.

## Manual MetaMask checklist

1. Configure gateway in Woo → Settings → Payments → Ax402.
2. Place an order with Ax402.
3. On pay page, connect MetaMask on Base Sepolia.
4. Confirm USDC payment.
5. Confirm redirect to order-received and order status Processing/Completed.
