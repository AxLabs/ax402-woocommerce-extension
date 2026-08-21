# Ax402 WooCommerce Extension

WooCommerce **payment provider** plugin that accepts **x402 / Ax402** on-chain settlements for humans (wallet / MetaMask) and agents (buyer SDK / CLI).

The store catalog stays in **USD**. Settlement uses the same payment tokens Ax402 exposes on the platform (`USDC`, `USDT`, and other enabled assets) — merchants choose which to accept; shoppers (or agents) pick one at pay time. See [docs/context.md](docs/context.md).

This is a gateway extension — not a full storefront. Catalog, cart, customers, and the order list stay in WooCommerce.

## Features (v0)

- Checkout payment method `ax402`
- Multi-asset settlement from Ax402 platform `payment_tokens` (admin multi-select)
- Per-order gateway endpoints with multi-`accepts`, priced from the USD cart total
- Human pay page: settlement picker, network switch + balance checks, `@ax402/react-paywall`
- Agent REST: products, create order, order status (same multi-accept payment URL)
- Optional UCP for buying agents (`/.well-known/ucp`, `/wp-json/ucp/v1`) — off by default; see [docs/ucp.md](docs/ucp.md)
- Fulfill upstream secured by `order_key` + one-time token
- `wp-env` local development + demo product seed
- Unit + live control-plane tests

## Documentation

| Doc | Contents |
|---|---|
| [**Context**](docs/context.md) | What the extension is / is not, concerns, currency model |
| [**Local development**](docs/local-development.md) | Start wp-env, `.env`, seed products, tunnel, first payment |
| [**E2E environment**](docs/e2e.md) | Ready-to-go seed + ngrok + programmatic / MetaMask pay |
| [**UCP for agents**](docs/ucp.md) | Discovery, catalog, cart, checkout, order, x402 402-at-complete, min-leak adapter |
| [Architecture](docs/architecture.md) | Payment flow, settlement lock, ngrok upstream_auth, reconcile |
| [**Releases**](RELEASE.md) | SemVer, tagging, GitHub Releases, agent checklist |
| [Merchant setup](docs/merchant-setup.md) | Production checklist + agent buy sketch |
| [Testing](docs/testing.md) | Unit, live CP, E2E commands |

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
npm run env:e2e     # re-seed + E2E readiness (set WP_BASE_URL after ngrok)
```

| | |
|---|---|
| Store | http://localhost:8888 |
| Admin | http://localhost:8888/wp-admin |
| Login | `admin` / `password` |

Configure **WooCommerce → Settings → Payments → Ax402** (API key, pay-to wallet, settlement tokens). Orders appear under **WooCommerce → Orders**.

Full E2E (tunnel + pay): [docs/e2e.md](docs/e2e.md).

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
npm run test:e2e-pay     # needs public WP_BASE_URL + buyer key (see docs/e2e.md)
npm run test:e2e-ucp     # UCP agent flow; set AX402_UCP_ENABLED=yes then re-seed
```

See [docs/testing.md](docs/testing.md) and [docs/e2e.md](docs/e2e.md).

## Repository layout

```text
plugin/     WordPress plugin (gateway, REST, pay page, blocks)
bin/        seed-wp-env.sh, phpunit, package, version helpers
docs/       context, local-dev, architecture, merchant, testing
tests/      PHP / JS / Playwright / programmatic pay
RELEASE.md  versioning + GitHub release process
AGENTS.md   short agent entrypoint
```

## Releases

SemVer + tagged GitHub Releases (plugin zip attached). See **[RELEASE.md](RELEASE.md)**.

```bash
bash bin/check-version.sh
bash bin/bump-version.sh 0.2.0   # then commit, tag v0.2.0, push tag
```

## Security

Never commit API keys or private keys. Rotate any key that was shared in chat or logs.
