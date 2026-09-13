# Local development environment

This guide walks from a clean clone to a working WordPress + WooCommerce shop with the Ax402 payment gateway, demo products, and (optionally) a public tunnel for real payments.

For product context and what the plugin is *for*, see [context.md](context.md). Agent UCP (on by default): [ucp.md](ucp.md). x402 on UCP: [ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding).

---

## Prerequisites

| Tool | Notes |
|---|---|
| **Node.js 20+** | Repo has `.nvmrc` → run `nvm use` |
| **Docker** | Required by [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) (`wp-env`) |
| **npm** | Comes with Node |
| **Python 3** | Used by the seed script to pass `.env` into the container |
| **Ax402 seller account** | [ax402.io](https://ax402.io) — API key with `apiManager` scopes + a pay-to EVM address |
| **ngrok / Cloudflare Tunnel** (optional) | Needed for live gateway fulfill against your local shop |

---

## 1. Clone and install

```bash
git clone <this-repo-url> ax402-woocommerce-extension
cd ax402-woocommerce-extension

nvm use                 # Node 20
npm install             # root: wp-env, vitest, playwright, @ax402/sdk
npm --prefix plugin install
npm run build           # pay-page + blocks bundles → plugin/build/
```

---

## 2. Configure secrets (`.env`)

```bash
cp .env.example .env
```

Edit `.env` (never commit it):

| Variable | Required for | Description |
|---|---|---|
| `AX402_BASE_URL` | Seed / runtime overlay | Default `https://api.ax402.io`. Admin field is read-only; set this env var and re-seed to change it. |
| `AX402_API_KEY` | Gateway available at checkout | Seller `ax402_live_…` key |
| `AX402_PAY_TO_ADDRESS` | Gateway available at checkout | EVM address that receives USDC |
| `AX402_NETWORK` | Seed / gateway | `sepolia` or `mainnet` |
| `AX402_EVM_PRIVATE_KEY` | Programmatic E2E pay only | Buyer wallet for SDK pay tests |

**Important:** Checkout hides Ax402 unless both API key and pay-to address are set (`is_available()`). After seeding you should see `is_available=yes` in the seed log.

---

## 3. Start WordPress (wp-env)

```bash
npm run env:start
```

What this does ([`.wp-env.json`](../.wp-env.json)):

1. Starts WordPress (PHP 8.2) in Docker
2. Installs **WooCommerce 11.1.0** (pinned for the E2E target) + mounts [`plugin/`](../plugin/) as a plugin
3. Runs **`afterStart` → `bash bin/seed-wp-env.sh`** automatically

**Compatibility posture:** the local/E2E env always tracks a **current** WooCommerce (and WordPress) so we catch regressions early. The plugin still declares a lower floor for merchants on older-but-reasonable installs (`WC requires at least: 8.0`, `Requires at least: 6.0` for WordPress). Bump the pinned zip in `.wp-env.json` when you intentionally move the E2E target forward, then refresh with `npm run env:update`.

URLs:

| Surface | URL |
|---|---|
| Storefront | http://localhost:8888 |
| WP Admin | http://localhost:8888/wp-admin |
| **Admin user** | `admin` / `password` |

Useful commands:

```bash
npm run env:stop       # stop containers
npm run env:update     # re-download pinned sources (WC/WP) + re-apply config
npm run env:destroy    # wipe volumes (full reset)
npm run env:seed       # re-run seed anytime (safe / idempotent)
npm run env:e2e        # seed + E2E readiness (+ tunnel sync if WP_BASE_URL set)
npm run plugin-check   # WordPress Plugin Check (PCP); needs wp-env running
```

For the full payment E2E path (ngrok, upstream, `test:e2e-pay`), see **[e2e.md](e2e.md)**.

Rebuild JS after pay-page or blocks changes:

```bash
npm run build
# hard-refresh the browser; wp-env mounts plugin/ live
```

---

## 4. What the seed script does

[`bin/seed-wp-env.sh`](../bin/seed-wp-env.sh) is the single source of truth for local demo data. It runs on `env:start` and via `npm run env:seed`.

### Store options

- Currency → **USD**
- Default country → **US:CA**
- Price decimals → **4** (so `$0.001` / `$0.0003` display correctly)
- **Coming soon** mode → **off** (WooCommerce defaults this on for fresh installs)
- Activates the Ax402 plugin (WooCommerce is already provided by wp-env)

### Gateway settings

- Loads host `.env` and writes a short-lived `plugin/.seed-gateway.json` into the mounted plugin dir (wp-env PHP cannot see host `getenv`)
- Merges into WooCommerce gateway options + encrypted plugin settings
- **Does not wipe** existing API key / pay-to when env vars are empty
- Prints `gateway settings saved; is_available=yes|no`

### Demo catalog (6 products, idempotent by SKU)

Images live in [`plugin/assets/demo-products/`](../plugin/assets/demo-products/).

| SKU | Name | Price |
|---|---|---|
| `ax402-micropay` | Ax402 E2E Micropay | $0.01 |
| `ax402-spark` | Ax402 Spark Note | $0.02 |
| `ax402-signal` | Ax402 Signal Pulse | $0.001 |
| `ax402-dust` | Ax402 Dust Credit | $0.0003 |
| `ax402-pass` | Ax402 Access Pass | $0.10 |
| `ax402-pack` | Ax402 Starter Pack | $0.25 |

Re-running seed updates names/prices/images for existing SKUs and creates any missing ones.

---

## 5. Verify the install

1. Open http://localhost:8888/wp-admin → log in (`admin` / `password`)
2. **WooCommerce → Settings → Payments → Ax402 (x402)**
   - Enabled
   - API key / pay-to / network match `.env`
3. **Products** — six demo SKUs with images
4. Storefront shop — prices like `$0.001` (not `$0.00`)
5. Optional CLI check:

```bash
npx wp-env run cli wp eval '
$g = new Ax402_WC_Gateway_Ax402();
echo "is_available=" . ($g->is_available() ? "yes" : "no") . "\n";
'
```

If checkout says **“There are no payment methods available”**, the gateway is almost always missing API key or pay-to — re-run `npm run env:seed` with a filled `.env`.

---

## 6. Human checkout (local, wallet)

1. Shop → add a product → Checkout
2. Fill billing (email required) → select **Pay with Ax402** → Place order
3. You land on the store pay page (`?ax402_pay=1`)
4. Connect MetaMask on the network matching gateway settings (Base Sepolia or Base mainnet)
5. Confirm USDC payment → order should move to Processing/Completed

**Orders admin:** **WooCommerce → Orders** — open the order for email, totals, currency, status, and Ax402 meta / notes.

### Public tunnel (required for real gateway upstream)

The Ax402 gateway must reach your WordPress fulfill URL. `localhost` is not enough for end-to-end settlement.

Typical flow with ngrok:

```bash
# terminal 1 — shop already on :8888
ngrok http 8888
# note https://xxxx.ngrok-free.app
```

Then point WordPress and the Ax402 API at that public origin:

```bash
# Example — replace with your tunnel URL
npx wp-env run cli wp config set WP_HOME 'https://xxxx.ngrok-free.app'
npx wp-env run cli wp config set WP_SITEURL 'https://xxxx.ngrok-free.app'
```

Also ensure the Ax402 store API `upstream_base_url` matches that origin (re-save Payments → Ax402 to trigger onboarding, or update via control plane). Details in [merchant-setup.md](merchant-setup.md).

---

## 7. Agent / programmatic buy

With the shop reachable (local for product list; public URL for gateway settle):

```http
GET  /wp-json/ax402/v1/products
POST /wp-json/ax402/v1/orders
Content-Type: application/json

{ "line_items": [{ "product_id": 18, "quantity": 1 }] }
```

Response includes `payment_url` (Ax402 gateway). Pay with buyer SDK/CLI, then:

```http
GET /wp-json/ax402/v1/orders/{order_key}
```

See [e2e.md](e2e.md) for the ready-to-go E2E seed + `npm run test:e2e-pay`.

---

## 8. Repository map

```text
ax402-woocommerce-extension/
├── .env / .env.example      # secrets (gitignored .env)
├── .wp-env.json             # WordPress env + afterStart seed
├── bin/
│   ├── seed-wp-env.sh       # currency, gateway, demo products
│   ├── run-phpunit.sh
│   └── package-plugin.sh
├── docs/                    # this documentation
├── plugin/                  # WordPress plugin (mounted into wp-env)
│   ├── ax402-for-woocommerce.php
│   ├── includes/            # PHP gateway, REST, settings
│   ├── src/                 # JS sources (pay-page, blocks)
│   ├── build/               # compiled assets
│   └── assets/demo-products/
└── tests/                   # PHP unit/integration/live, JS, e2e
```

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| “Pardon our dust / Coming soon” for guests | WooCommerce Coming soon mode | Seed sets `woocommerce_coming_soon=no`; or WooCommerce → Settings → Site visibility |
| No payment methods at checkout | Missing API key or pay-to | Fill `.env`, `npm run env:seed` |
| Seed wiped credentials | Old seed without merge (fixed) | Ensure current `bin/seed-wp-env.sh`; re-seed with `.env` |
| Prices show `$0.00` for `$0.001` | 2 decimal places | Seed sets 4 decimals; enable Ax402 (plugin also raises display decimals) |
| Pay page “Missing gateway URL” | Endpoint prep failed / meta missing | Check API key, network asset enabled, WP debug log |
| Browser CORS to `*.ax402.io` | Direct browser fetch | Plugin auto-adds store origin via `/apis/{id}/cors` (gateway binary must support per-API CORS) |
| Gateway cannot fulfill | Upstream is localhost, or free ngrok interstitial (`ERR_NGROK_6024`) | Use a public tunnel + matching `WP_HOME` / `upstream_base_url`. Plugin adds `ngrok-skip-browser-warning` via endpoint `upstream_auth` when the host contains `ngrok` — see [architecture.md](architecture.md). |
| Paid on-chain but order stays pending | Upstream miss; reconcile off | Enable **Settlement reconcile** under Payments → Ax402, or fix upstream; see architecture payment flow |
| Sepolia endpoint create fails | Seller account missing Sepolia USDC asset | Use `AX402_NETWORK=mainnet` for smoke tests |
| Docker permission errors | Docker daemon not running / sock access | Start Docker Desktop; retry `npm run env:start` |
| Plugin JS not updating | Stale build | `npm run build` + hard refresh |

---

## 10. Tests (quick reference)

```bash
npm test                 # PHP unit + integration + JS unit
npm run test:live-cp     # live Ax402 control plane (needs API key)
npm run test:e2e-pay     # programmatic pay (needs public WP_BASE_URL + buyer key)
```

Full details: [testing.md](testing.md).

---

## Security reminders

- Never commit `.env`, `plugin/.seed-gateway.json`, or `plugin/.e2e-config.json`
- Do not paste live API keys or private keys into tickets/chat
- Rotate any key that was exposed
