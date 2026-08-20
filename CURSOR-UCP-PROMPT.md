# Task: Add UCP (Universal Commerce Protocol) support for AI agents to the Ax402 WooCommerce extension

## What you are building

Add a **UCP server implementation** to this WordPress/WooCommerce plugin so that **AI buying agents** can discover the store, create a checkout session, and pay with x402, end to end, without any human UI.

This is **agent-exclusive**. The existing human checkout flow (pay page, react-paywall, blocks) must not change at all. There is already a non-UCP "agent REST" API (`/wp-json/ax402/v1/*`) for agents; UCP is the standardized replacement for it. Keep the old agent REST for now (backwards compat), but build UCP as a clean, separate layer.

## Normative references (read these first)

1. **`/Users/machado/git-axlabs/github/ucp-x402-binding`** (local clone, AxLabs repo): the `org.x402.payment` UCP payment handler binding spec we are implementing. Especially:
   - `docs/01-handler-spec.md` (handler entry fields, naming rules)
   - `docs/02-wire-binding.md` (the HTTP flow: create session, 402 challenge, payment retry)
   - `docs/03-amount-semantics.md` (FX and amount rules)
   - `examples/checkout-flow.md` + `examples/res/*.json` (exact response shapes to mirror)
   - `examples/discovery.json` (exact discovery document shape to mirror)
   - `schema/handler.schema.json` (machine-readable handler entry)
2. **UCP spec repos** (for exact field shapes; already validated, do not invent fields):
   - `Universal-Commerce-Protocol/ucp` on GitHub: `docs/specification/shopping/checkout/rest.md` (checkout REST), `source/schemas/profile.json` + `source/schemas/ucp.json` (discovery profile), `source/schemas/payment_handler.json`, `source/schemas/shopping/checkout.json`, `source/schemas/shopping/payment.json`, `source/schemas/shopping/types/payment_instrument.json`, `docs/specification/payment-handler-guide.md`

Key verified facts from the UCP schemas (do not deviate):
- Profile document at `/.well-known/ucp`: root requires `ucp`; `ucp` (business schema) requires `services` and `payment_handlers`; optional `keys` (JWK Set).
- Handler entries: `id`, `version` (required; version format is **YYYY-MM-DD**, not semver), `spec`, `schema` (URIs), optional `available_instruments`, optional `config`.
- Checkout session response: required `ucp, id, line_items, status, currency, totals, links`; `payment` object is **optional at create, required at complete**; `payment.instruments[]` entries require `id`, `handler_id`, `type`, optional `selected: true`, `additionalProperties: true` (handler-specific extras allowed).
- UCP field names are **snake_case** everywhere. camelCase only inside verbatim x402 wire payloads (`PaymentRequired` fields like `maxAmountRequired`, `payTo`, `validUntil`).
- Amounts are integer **base units as strings** in x402 payloads; UCP `totals`/`price` are integer **minor units** of the store currency.

## Existing architecture you must reuse (do not duplicate)

The plugin already has a complete x402 payment pipeline. Read these files before designing anything:

- `plugin/includes/class-order-payment.php`: `prepare($order)` creates one temporary Ax402 endpoint per enabled settlement token (single accept each), stores settlement options in order meta; `lock_settlement_token($order, $token_id)` selects/locks one token's endpoint.
- `plugin/includes/class-agent-rest-controller.php`: the existing (non-UCP) agent REST. Your UCP controllers will wrap the same internals.
- `plugin/includes/class-fulfill-controller.php`: the gateway calls this after on-chain settlement to mark the order paid (`payment_complete()`).
- `plugin/includes/class-settlement-reconcile.php`: safety net that marks orders paid by querying control-plane settlements by endpoint_id.
- `plugin/includes/class-platform-tokens.php`: `build_settlement_options()` builds per-token accept payloads (network, asset, decimals, amount, FX via `Ax402_WC_Composite_Exchange_Rates`), `build_accept()` builds x402 accept objects.
- `plugin/includes/class-control-plane-exchange-rates.php`: FX provider (`GET /exchange-rates?quote=usd&date=...`, today then yesterday then previous business day). **Rates carry a date**: expose it in UCP responses where relevant (see FX section).
- `plugin/includes/class-gateway-proxy-controller.php`: existing same-origin proxy pattern for forwarding `Payment-Signature`/`Payment-Required` headers to the gateway. Reuse its header handling approach.
- `plugin/includes/class-plugin.php`: registration/bootstrap wiring.
- `docs/architecture.md` and `docs/context.md`: the human flow, dual EVM/Hedera APIs, ngrok upstream_auth, reconcile.

Trust model reminders: fulfill is authorized by `order_key` + one-time `fulfill_token` embedded in the endpoint path. Store currency is USD. Settlement tokens come from Ax402 platform config (`GET /config/platform`), nothing hard-coded per chain.

## Required deliverables

### 1. UCP discovery: `GET /.well-known/ucp`

Serve a UCP business profile at the site root `/.well-known/ucp` (outside `wp-json`; use a rewrite rule + `parse_request`/`template_redirect` hook, serve `application/json`, no-store cache headers).

Shape (mirror `examples/discovery.json` in the binding repo):

```json
{
  "ucp": {
    "version": "2026-01-11",
    "services": ["<site base url>"],
    "capabilities": {
      "dev.ucp.shopping.checkout": [{ "version": "2026-01-11", "spec": "<binding repo checkout docs url>" }]
    },
    "payment_handlers": {
      "org.x402.payment": [
        {
          "id": "org.x402.payment",
          "version": "2026-08-20",
          "spec": "https://github.com/AxLabs/ucp-x402-binding",
          "schema": "https://github.com/AxLabs/ucp-x402-binding/blob/main/schema/handler.schema.json",
          "available_instruments": [{ "type": "x402" }],
          "x402": {
            "networks": ["eip155:8453", "..."],
            "assets": [
              { "network": "eip155:8453", "asset": "0x...", "decimals": 6, "symbol": "USDC" }
            ],
            "max_amount": "<optional, base units, from settings>",
            "quote_window": 600,
            "schemes": ["exact"],
            "network_schemas": { "eip155": "<binding repo schema url>", "neo": "...", "solana": "..." }
          }
        }
      ]
    },
    "map_order": "<site>/wp-json/ucp/v1/orders/{id}"
  }
}
```

Rules:
- Build `networks`/`assets` from the merchant's enabled settlement tokens (`Ax402_WC_Settings::enabled_token_ids()` + platform config). One asset entry per (network, asset) pair, deduplicated.
- `network_schemas`: include only namespaces actually present in assets, pointing at the binding repo's `schema/networks/<namespace>.schema.json` URLs. Include `hedera` if Hedera tokens are enabled (a `hedera` schema does not exist yet in the binding repo; if no schema exists for a namespace, omit that key rather than inventing a URL).
- **No facilitator/gateway information anywhere in the profile.** No gateway hosts, no api ids, no endpoint ids. This is a hard rule from the binding spec.
- Cache the computed profile in a transient (e.g. 5 min) keyed on settings + platform config version; bust on settings save.

### 2. UCP checkout REST under `/wp-json/ucp/v1`

Implement the checkout capability subset from the UCP checkout REST spec. Namespaced routes (the plugin cannot own `/checkout-sessions` at site root; advertise the real base in the profile, e.g. via a `services`/capability link or a `base_url` field in the handler `config` if the spec allows; otherwise document the path convention in docs and mirror it in `map_order`):

- `POST /wp-json/ucp/v1/checkout-sessions`: create session. Body: `{ line_items: [{ item: { id }, quantity }], buyer?: { email } }`. Creates a WooCommerce order (reuse the pattern in `create_order()`: pending status, gateway payment method, `calculate_totals()`), then calls `Ax402_WC_Order_Payment::prepare($order)`. Respond `201` with the UCP checkout shape from `examples/res/checkout-session-created.json`: `ucp` envelope (capabilities + runtime `payment_handlers` entry with `available_instruments: [{"type":"x402"}]`), `id` (use the **order key** as the session id; document that), `status: "ready_for_complete"`, `currency`, `line_items` (from the Woo order, with per-item `totals`), `buyer`, `totals` (`subtotal`/`tax`/`total` minor units from the order), `links` (terms_of_service etc. from WooCommerce pages if set).
- `GET /wp-json/ucp/v1/checkout-sessions/{session_id}`: session status. Run `Ax402_WC_Settlement_Reconcile::reconcile_order()` first (same as the existing agent REST `get_order`). Return the same shape; if paid, add `order: { id, permalink_url }` and the payment instrument with settlement facts.
- `POST /wp-json/ucp/v1/checkout-sessions/{session_id}/complete`: the core of the flow (see next section).
- `POST /wp-json/ucp/v1/checkout-sessions/{session_id}/cancel`: optional, nice to have. Cancels the Woo order if unpaid. Skip if it adds complexity.

### 3. The complete + 402 + payment retry flow (the heart of the task)

This must match `docs/02-wire-binding.md` and `examples/checkout-flow.md` in the binding repo:

**Step A, complete without payment.** Agent POSTs complete with no `PAYMENT-SIGNATURE` header. The plugin responds `402 Payment Required` with:
- `PAYMENT-REQUIRED` header: base64url-encoded x402 `PaymentRequired` payload
- Body: the UCP error shape (`ucp.status: "error"`, `messages: [{ code: "payment_required", severity: "recoverable", message }]`)

Asset selection problem: Ax402 endpoints are per-token (one endpoint per settlement token). The agent indicates its preferred asset in the complete request body as the UCP instrument selection: `payment.instruments: [{ id: "x402", handler_id: "org.x402.payment", type: "x402", selected: true, network: "eip155:8453", asset: "0x..." }]` (extra fields are legal, `additionalProperties: true`). Match against the order's settlement options. If no preference given, use the primary option. If the requested asset is not available for this order, return a UCP error message with `code: "payment_method_not_available"` and the available networks/assets in the message content.

Fetch the challenge: server-side GET the matching gateway URL (the per-token endpoint), relay the gateway's `402`, `PAYMENT-REQUIRED` header, and body to the agent. If the gateway errors, map to a UCP `error` message with `severity: "recoverable"`.

**Step B, complete with payment.** Agent re-POSTs complete with the `PAYMENT-SIGNATURE` header (the x402 payment payload) and the same instrument selection body. The plugin:
1. Locks the chosen token on the order (`lock_settlement_token()`) if not already locked.
2. Forwards the `PAYMENT-SIGNATURE` (and `PAYMENT-SIGNATURE-DATA` if present) server-side to the matching gateway endpoint URL (reuse the forwarding-header logic from `class-gateway-proxy-controller.php`; include the ngrok upstream_auth consideration if relevant, though server-side calls from the plugin usually need it only when the store itself is behind ngrok, which does not apply here).
3. On success: the gateway settles on-chain and proxies to the existing fulfill endpoint, which marks the order paid. The gateway returns `PAYMENT-RESPONSE` (the x402 receipt payload). The plugin responds `200` with the completed checkout shape from `examples/res/checkout-complete-success.json`: `status: "completed"`, `order: { id, permalink_url }`, `payment.instruments[]` with the selected x402 instrument carrying settlement facts in `display` (network, symbol, amount, transaction hash from the payment response) and the verbatim x402 receipt under an `x402_receipt` field.
4. On gateway 402/rejection: return the UCP error shape with the gateway's reason (e.g. `payment_failed`, offer expired), session stays `ready_for_complete` so the agent can retry with a fresh challenge.
5. Race safety: the gateway fulfill is asynchronous relative to this request. After forwarding, poll the order status briefly (e.g. up to ~10s, 1s interval) for `payment_complete` via fulfill or `Ax402_WC_Settlement_Reconcile::reconcile_order()` before responding. If settlement verified but fulfill has not landed within the window, still return `completed` (reconcile is the safety net) but include a message that confirmation is pending on-chain finality.

**Important design decision to make explicitly (and document): facilitator hiding.** All UCP responses must be served from the WooCommerce origin. Gateway URLs must never appear in any UCP discovery, session, or 402 response. The plugin proxies both the challenge fetch and the payment submission server-side. This differs from the legacy agent REST (which hands out `payment_url` directly); that legacy behavior stays as-is for the old API but the UCP layer is strict. If proxying the payment submission proves problematic in practice (e.g. signature payload bound to gateway origin), document the constraint and fall back to returning the gateway payment URL as the x402 `resource` inside the PaymentRequired payload only (never as a separate field), which is the minimum leak acceptable per the binding.

### 4. Settings

Add to the Ax402 gateway settings page:
- `ucp_enabled` (checkbox, default off): enables `/.well-known/ucp` and the `ucp/v1` routes. Separate from the human gateway; a merchant can run UCP even if they hide the human pay page.
- `ucp_max_amount` (optional string, base units): surfaced as `x402.max_amount` in the profile if set.
- Reuse existing API key / pay-to / token selection. UCP has no separate credentials.

Invalidate the profile transient on settings save.

### 5. FX handling (already mostly built; expose it correctly)

Store total is USD. For 1:1 USD-pegged tokens, amounts map directly. For others, `build_settlement_options()` already converts via the control-plane rate provider. For UCP responses:
- The converted amount lives in the x402 `PaymentRequired` payload (`maxAmountRequired`), which comes from the gateway. Do not recompute.
- When the order's settlement options include a non-1:1 token, the `GET /checkout-sessions/{id}` response SHOULD include the quote metadata the merchant used: per the binding's amount-semantics doc, a `quote` object with `{ rate, base, target, capturedAt, source }`. The rate provider caches per-date responses; thread the rate date (and source, e.g. "ax402-control-plane") from `build_settlement_options()` through order meta into the UCP response. Keep this minimal: store the rate + date + source in settlement option meta at prepare time.

### 6. Testing

- **PHPUnit unit tests** (follow existing test patterns in `tests/`): profile builder (correct handler entry from fixture platform config, snake_case, no gateway leakage: assert no gateway host/api id/endpoint id appears in the profile JSON), session create/complete mapping (shapes match `examples/res/*.json` field names), 402 relay logic with a mocked control-plane/gateway client, asset preference matching, FX quote passthrough.
- **E2E (wp-env)**: extend the existing E2E setup (`docs/e2e.md`): a scripted agent client that does discovery, session create, complete (expects 402 with PAYMENT-REQUIRED), pays with the x402 buyer client against the plugin-proxied challenge, completes (expects 200 + receipt), then GET session shows completed. Reuse whatever wallet/funding mechanism the existing E2E agent payment test uses.
- Validation script: `bin/` helper or PHPUnit test that fetches `/.well-known/ucp` from the running env and validates the handler entry against the binding repo's `schema/handler.schema.json` (vendor a copy or fetch by URL at test time with a local fallback).

### 7. Docs

- New `docs/ucp.md`: what UCP support is, the agent flow diagram (discovery, session, 402, pay, complete), facilitator-hiding rule, how it maps to existing components, settings, testing.
- Update `docs/context.md` (buyer types table gains "UCP agent"), `docs/architecture.md` (UCP components section), `README.md` (feature list), `AGENTS.md` (layout entry).

## Non-negotiable constraints

1. **Zero changes to the human flow.** Pay page, blocks checkout, legacy agent REST: untouched behavior.
2. **No facilitator leakage** in any UCP response (see above).
3. **Reuse the payment pipeline** (`prepare()`, `lock_settlement_token()`, fulfill, reconcile). Do not create a parallel endpoint-preparation path; if `prepare()` needs a new return field (e.g. rate metadata), extend it.
4. **UCP shapes are not yours to invent.** Field names and structures come from the UCP schemas and the binding repo examples. When genuinely ambiguous, prefer the binding repo's examples and document the choice. Never mix camelCase into UCP-defined fields.
5. **Store currency USD only** (same as today); reject others at session create with a clear UCP error.
6. WordPress coding standards as used in the repo (strict types, `ABSPATH` guards, escaping, text domain `ax402-for-woocommerce`).
7. Version bumps: read `RELEASE.md` first. This work targets the next minor version.

## Suggested implementation order (refine into your own plan)

1. Read all files listed above; run existing tests (`bash bin/run-phpunit.sh`) to confirm a green baseline.
2. Settings + profile builder + `/.well-known/ucp` route + unit tests.
3. Session create/get + shape mapping + unit tests.
4. Complete: 402 relay (Step A) + unit tests with mocked gateway.
5. Complete with payment: proxy + poll/reconcile + receipt mapping (Step B) + unit tests.
6. FX quote metadata passthrough.
7. E2E agent flow in wp-env.
8. Docs.

Open design points where you should propose and decide (document each in `docs/ucp.md`):
- Session id = order key: confirm or propose better (order id vs key; key is already the unguessable public handle used by the legacy agent REST).
- How the agent's asset preference travels in the complete body (instrument extra fields vs a dedicated convention); keep it spec-legal.
- Poll window and messaging when settlement verified but fulfill is still in flight.
- Whether to include `map_order` in the profile (it appears in the binding's discovery example; verify against the profile schema whether it is a standard field or an extension; treat as extension if unsure).

## Definition of done

- `/.well-known/ucp` serves a profile that validates against the binding repo's handler schema and contains zero facilitator data.
- A scripted agent completes the full flow (discover, create, 402, pay, complete, verify) against wp-env with a real settlement on the dev network.
- All existing tests still pass; new unit + E2E tests pass.
- Docs updated; no human-flow regressions; PSR-autoloadable new classes follow the existing `class-*.php` naming and autoloader registration.
