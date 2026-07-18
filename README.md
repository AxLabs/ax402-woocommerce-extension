# Ax402 WooCommerce Extension

WooCommerce **payment provider** plugin that accepts **x402 / Ax402** USDC payments for humans (wallet / MetaMask) and agents (buyer SDK / CLI).

This is a gateway extension — not a full storefront. Catalog, cart, customers, and the order list stay in WooCommerce. See [docs/context.md](docs/context.md).

## Features (v0)

- Checkout payment method `ax402`
- Per-order Ax402 gateway endpoints priced to the cart total
- Human pay page with `@ax402/react-paywall` (same-origin proxy for CORS)
- Agent REST: products, create order, order status
- Fulfill upstream secured by `order_key` + one-time token
- `wp-env` local development + demo product seed
- Unit + live control-plane tests

## Documentation

| Doc | Contents |
|---|---|
| [**Context**](docs/context.md) | What the extension is / is not, concerns, currency model |
| [**Local development**](docs/local-development.md) | Start wp-env, `.env`, seed products, tunnel, first payment |
| [Architecture](docs/architecture.md) | Components, trust model, CORS |
| [Merchant setup](docs/merchant-setup.md) | Production checklist + agent buy sketch |
| [Testing](docs/testing.md) | Unit, live CP, E2E |

## Quick start

Full walkthrough: [docs/local-development.md](docs/local-development.md).

```bash
nvm use
cp .env.example .env
# fill AX402_API_KEY, AX402_PAY_TO_ADDRESS, AX402_NETWORK

npm install
npm --prefix plugin install
npm run build

npm run env:start   # WordPress + WooCommerce + plugin + auto-seed
# or later: npm run env:seed
```

| | |
|---|---|
| Store | http://localhost:8888 |
| Admin | http://localhost:8888/wp-admin |
| Login | `admin` / `password` |

Configure **WooCommerce → Settings → Payments → Ax402**. Orders appear under **WooCommerce → Orders**.

### Demo catalog (seeded)

| Product | Price |
|---|---|
| Ax402 E2E Micropay | $0.01 |
| Ax402 Spark Note | $0.02 |
| Ax402 Signal Pulse | $0.001 |
| Ax402 Dust Credit | $0.0003 |
| Ax402 Access Pass | $0.10 |
| Ax402 Starter Pack | $0.25 |

## Tests

```bash
npm test                 # PHP unit + integration + JS unit
npm run test:live-cp     # needs AX402_API_KEY (+ PAY_TO for create/delete)
npm run test:e2e-pay     # needs public WP_BASE_URL + buyer key
```

See [docs/testing.md](docs/testing.md).

## Repository layout

```text
plugin/     WordPress plugin (gateway, REST, pay page, blocks)
bin/        seed-wp-env.sh, phpunit, package
docs/       context, local-dev, architecture, merchant, testing
tests/      PHP / JS / Playwright / programmatic pay
```

## Security

Never commit API keys or private keys. Rotate any key that was shared in chat or logs.
