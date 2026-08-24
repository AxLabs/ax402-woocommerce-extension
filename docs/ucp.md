# UCP (Universal Commerce Protocol) for agents

This plugin exposes a **separate** UCP shopping + x402 payment surface so buying agents can discover the store, browse the catalog, complete a checkout (including WooCommerce tax/shipping), and pay with x402.

Protocol version: **2026-04-08** (current `ucp.dev` release). Discovery advertises both REST and MCP. The Shopify `ucp` CLI (0.6.x) negotiates MCP only.

Humans keep using checkout → pay page. Legacy agents keep using `/wp-json/ax402/v1`. UCP is **off by default**.

| Buyer | Surface |
|---|---|
| Human | WooCommerce checkout → Ax402 pay page |
| Legacy agent | `/wp-json/ax402/v1/products` + `/orders` (hands out `payment_url`) |
| UCP agent | `GET /.well-known/ucp` + REST `/wp-json/ucp/v1` and MCP `/wp-json/ucp/v1/mcp` |

Enable it in **WooCommerce → Settings → Payments → Ax402 → UCP for agents**. It does not require the human payment method to be offered at checkout, but it does require the same API key, pay-to address, and settlement tokens that `prepare()` already uses.

---

## Spec sources (do not mix them)

| Concern | Follow this |
|---|---|
| Discovery `services` / `capabilities` / `payment_handlers` as **maps of arrays**, REST `endpoint` | Official UCP **2026-04-08** (`ucp.dev/2026-04-08`, checkout REST + MCP OpenRPC) |
| Catalog `POST /catalog/{search,lookup,product}` | Official UCP catalog REST |
| Cart `POST/GET/PUT /carts`, `POST /carts/{id}/cancel` | Official UCP cart |
| Checkout create / GET / PUT / complete / cancel, `fulfillment` | Official UCP checkout + fulfillment |
| Order `GET /orders/{id}` | Official UCP order |
| `org.x402.payment` handler fields | [ucp-x402-binding](https://github.com/AxLabs/ucp-x402-binding) `schema/handler.schema.json` |
| HTTP 402 at `POST …/complete` | Binding `docs/02-wire-binding.md` |

The binding `examples/discovery.json` now follows official UCP **2026-04-08** (maps of arrays, dated capability versions, REST+MCP). This plugin matches that shape. Still omit `map_order` (optional key-order hint). Do not copy older drafts that used a URL-list `services` or a single handler object.

---

## Payment hop (min-leak adapter)

This is the important part. Read it before changing complete.

### Textbook x402

Client requests **URL A** → 402 with `resource` = A → retries **A** with `PAYMENT-SIGNATURE`. A facilitator (`/verify`, `/settle`) is merchant-side only. Clients never see it.

### Ax402 today

The **gateway URL is the resource server**. Humans pass `resourceUrl={gatewayUrl}` on the pay page. Legacy agents receive `payment_url`. After settlement the gateway calls Woo fulfill. That is a valid x402 payment **of the gateway resource**.

### UCP binding ideal

`resource` = shop checkout-session URL. Agent retries shop `complete`. Ax402 would be a hidden facilitator. **Ax402 does not verify a shop URL today**, so rewriting `resource` would break settlement.

### What this plugin does (v0.2)

```text
Agent                         Shop UCP                         Ax402 gateway
  |                              |                                   |
  | POST .../complete            |                                   |
  |   (no PAYMENT-SIGNATURE)     |                                   |
  |----------------------------->| GET locked token endpoint         |
  |                              |---------------------------------->|
  |                              | 402 PAYMENT-REQUIRED              |
  |                              |   resource = gateway URL          |
  | 402 + same PAYMENT-REQUIRED  |<----------------------------------|
  |<-----------------------------|                                   |
  | sign for resource=gateway    |                                   |
  | POST .../complete            |                                   |
  |   + PAYMENT-SIGNATURE        |                                   |
  |----------------------------->| POST gateway URL + signature      |
  |                              |---------------------------------->|
  | 200 UCP completed            | settle + fulfill/reconcile        |
  |<-----------------------------|                                   |
```

Rules:

- **UCP JSON** (discovery, catalog, session bodies, REST 402 **JSON body**) never contains gateway hosts, API ids, endpoint ids, fulfill tokens, or `payment_url`.
- The **PAYMENT-REQUIRED header** still contains the Ax402 gateway URL inside x402 `resource`. That is the only leak on the REST challenge, and it is required for Ax402 verification.
- **MCP** copies that same `PaymentRequired` object into the tool result: x402-standard `structuredContent` (x402Version / resource / accepts at the top of the object), nested `structuredContent.payment_required` for UCP session clients, and a MAY mirror at `result._meta["x402/payment-required"]`. JSON-RPC stays HTTP 200. That is the same leak as the header, in JSON, and only on MCP complete.
- If the gateway’s **PAYMENT-RESPONSE** includes a gateway `resourceUrl`, the plugin **redacts** those URLs inside the UCP JSON `x402_receipt` field. The `PAYMENT-RESPONSE` header is forwarded unchanged.
- UCP agents **must retry shop `complete`**, not `resource.url`.
- A generic SDK that POSTs to `resource.url` hits the gateway directly. The order can still fulfill via the existing gateway → Woo path; `GET` the session afterwards. That is accidental compatibility, not the intended agent path.
- Hedera `PAYMENT-SIGNATURE` JWTs are often larger than Apache/ngrok header limits (`LimitRequestFieldSize`, ngrok `ERR_NGROK_*` header-too-large). WordPress cannot raise those. REST **JSON body** `payment.payment_signature` / `payment.payment_signature_data` is first-class (preferred over the header when both are present). MCP retry uses `params._meta["x402/payment"]` or the same body fields. Header retry only works when every hop allows an 8KB+ header.

Strict hide (`resource` = shop complete URL) is deferred until Ax402 can verify shop-bound payments.

The same-origin **pay-proxy** used by the human pay page is unchanged. UCP complete uses `Ax402_WC_Ucp_Gateway_Http` instead of streaming through that proxy.

---

## Agent flow

1. `GET /.well-known/ucp` → official 2026-04-08 business profile.
   - REST: `services["dev.ucp.shopping"]` entry with `transport: rest` → `{shop}/wp-json/ucp/v1`
   - MCP: same capability, `transport: mcp` → `{shop}/wp-json/ucp/v1/mcp` (JSON-RPC `initialize` / `ping` / `tools/list` / `tools/call`, plus OpenRPC method names as aliases)
2. REST: `POST {endpoint}/catalog/search` with `{ "query": "micropay" }` (default page size 10). MCP: `search_catalog` with `{ catalog: { query } }`.
3. Optional cart: `POST {endpoint}/carts` with `line_items`, then `POST {endpoint}/checkout-sessions` with `{ "cart_id": "<cart id>" }` (same Woo order; overlapping checkout fields are ignored). Or skip the cart and `POST /checkout-sessions` with `line_items` directly. `id` is the Woo product id (string), variation id, or SKU.
4. If the SKU needs shipping: `PUT` the **checkout** session with `fulfillment.methods[]` destinations (`type: shipping`), or select a `pickup` location if the shop offers WooCommerce Local pickup. Then set `groups[].selected_option_id` from the returned rates. Woo tax/shipping land on the order. Virtual/downloadable SKUs omit checkout `fulfillment` (UCP checkout methods are only `shipping` | `pickup`).
5. When `status` is `ready_for_complete`, the session includes an `info` `payment_required` message plus `links[]` type `org.x402.complete` (REST POST URL). `ucp checkout complete` / MCP `complete_checkout` **without** an x402 signature does **not** place the order: REST returns HTTP 402 + `PAYMENT-REQUIRED`; MCP stays HTTP 200 with `structuredContent` as a PaymentRequired (and nested `payment_required`). Pay that REST complete URL with **any** x402 wallet (`PAYMENT-SIGNATURE` or JSON `payment.payment_signature`), or retry MCP `complete_checkout` with `params._meta["x402/payment"]`. Do not POST the MCP JSON-RPC URL as the x402 resource. Then GET the checkout and GET the order. Binding: https://github.com/AxLabs/ucp-x402-binding
6. Sign the challenge (including gateway `resource` inside the x402 payload).
7. Retry the same complete hop with the signature (REST header or MCP `_meta`).
8. `200` session `status: completed` with `order.id` (Woo order id) and `order.permalink_url`. MCP also puts the decoded receipt on `result._meta["x402/payment-response"]`.
9. `GET {endpoint}/orders/{order.id}` for the post-purchase order (expectations, events). `GET` the checkout session anytime. Already-paid complete is **idempotent 200**.

`UCP-Agent: profile="https://…"` is parsed when present and **not required** (UCP says MUST; requiring it would break our E2E and many clients).

---

## Session id, money, prepare()

**Cart and checkout session id = Woo `order_key`.** Same unguessable public handle as legacy agent REST. **Order resource `id`** is the Woo order id (the value in `checkout.order.id`); `GET /orders/{id}` also accepts the order key.

**UCP amounts** are ISO 4217 **integer USD cents**. Human catalog can still show sub-cent prices (`$0.001`). Those SKUs are **omitted** from UCP search; lookup/`get_product` reports `not_found`; checkout rejects them. Do not silently map `$0.001` to `$0.00`.

Order-level tax/shipping remainders beyond 2 decimals are **ceiled** to the next cent so we never undercharge.

**`Ax402_WC_Order_Payment::prepare()` runs only when the session is `ready_for_complete`.** Creating a session for a shippable SKU does **not** create Ax402 endpoints yet (legacy agent REST still prepares immediately — that path is unchanged). If line items or address change after prepare, endpoints are deleted and recreated for the new total.

The complete request polls fulfill/reconcile for up to ~10 seconds inside that HTTP call. Agents do not observe `complete_in_progress` on the wire; they get `402` or `completed` (or a recoverable error).

Status machine (UCP 2026-04-08 enums): `incomplete` → `ready_for_complete` → (402 stays ready) → `completed` / `canceled`. If a destination is set but Woo has no shipping rates, status is `requires_escalation` (not a message code) and `continue_url` is the human checkout URL. Gateway reject returns a recoverable `payment_failed` and stays `ready_for_complete`. We do not mark `payment_failed` as a terminal UCP status while a settlement might still land. Non-terminal sessions include `continue_url` (MUST for `requires_escalation`). `complete_in_progress` is not observed on the wire (verify+settle runs inside complete).

Non-1:1 settlement tokens persist `rateDate` / `rateSource` / `capturedAt` on order settlement-option meta (additive; the human pay page ignores unknown keys). `GET` session includes a UCP `quote` object when a non-1:1 option exists.

MCP tools: catalog (`search_catalog`, `lookup_catalog`, `get_product`), cart (`create_cart`, `get_cart`, `update_cart`, `cancel_cart`), checkout (`create_checkout`, `get_checkout`, `update_checkout`, `complete_checkout`, `cancel_checkout`), order (`get_order`). Handshake methods: `initialize`, `notifications/initialized`, `ping`. OpenRPC method names (`complete_checkout`, …) are accepted at the JSON-RPC top level as well as via `tools/call`.

---

## UCP CLI (`ucp` 0.6.x)

Shopify’s CLI negotiates **MCP only**. Two wire details that break agents if ignored:

1. **`--input` is wrapped** under `cart` / `checkout` / `catalog`. Pass **flat** fields (`line_items`, `fulfillment`, `query`). Example: `ucp cart create --input '{"line_items":[{"item":{"id":"18"},"quantity":1}]}'`. Nested `{ "cart": { "line_items": … } }` is also accepted (including a CLI double-wrap).
2. **Resource ids are positional** on get/update/complete (`ucp checkout update <id> --input '…'`). Create ops take no id. That is CLI shape, not this plugin.
3. **Postal fields are UCP 2026-04-08 names:** `street_address`, `address_locality`, `address_region`, `postal_code`, `address_country`. `ucp checkout update --input-schema` now lists those. Common aliases (`address_line_1`, `city`, `country`, …) are accepted so a mistyped payload is not silently dropped.
4. **`ucp checkout complete` without an x402 signature does not settle.** Pay the **shop** REST complete URL (`links[]` type `org.x402.complete`). That is the x402 resource the wallet should POST. `payment_required.resource.url` is the Ax402 gateway URL **inside** the signed challenge (min-leak adapter) — do not treat it as the UCP complete URL, and do not POST `/wp-json/ucp/v1/mcp`. Then `ucp checkout get` / `ucp order get`. Binding: https://github.com/AxLabs/ucp-x402-binding
5. **Discovery `x402.assets` is merchant capability, not this order’s quote.** Each enabled settlement token gets its **own** Ax402 endpoint (one `accept`). Complete without an instrument preference 402s the **first** prepared token, or the token stored from `checkout update` / a prior complete. `payment_required.accepts` is **that resource only** — other networks (e.g. XGAS on `eip155:47763`) will not appear there, even though `GET` checkout `payment.instruments[]` lists every prepared token. To quote another instrument, `PUT`/`update` or retry complete with that instrument `selected` (network + asset), then pay **the new** challenge as-is. Filtering a wallet pay to a network/asset that is not in that challenge’s `accepts[]` will fail (`no accept matched filters`). Tokens with no USD rate are omitted from the quote (order note: “Omitted (no USD rate)”). Do not merge every asset into one `payment_required` — that would bind the wrong x402 resource.

WooCommerce has no first-class gift checkout; this plugin does not advertise `is_gift`. Put a gift note in the buyer name / merchant-hosted continue URL if you need one.

CLI smoke (no wallet): `npm run test:e2e-ucp-cli` with `WP_BASE_URL` https and `ucp` on PATH. Skips if the CLI is missing.

---

## Fulfillment: virtual vs shipping vs pickup

WooCommerce already stores this on the product:

| WooCommerce | How we read it | UCP |
|---|---|---|
| **Virtual** (`_virtual`) | `WC_Product::is_virtual()` | Digital good. `needs_shipping()` is false. |
| **Downloadable** (`_downloadable`) | `WC_Product::is_downloadable()` | Still digital for fulfillment (file delivery). Catalog `tags` include `downloadable`. |
| **Needs shipping** | `WC_Product::needs_shipping()` | Physical. Checkout offers `shipping`, and `pickup` when Woo Local pickup / Pickup location rates exist. |
| **Local pickup** | Shipping method id `local_pickup` or Blocks `pickup_location` | Checkout `fulfillment.methods[].type: pickup` with retail locations (Blocks pickup locations, else the store address). |

UCP **checkout** method types are only `shipping` and `pickup`. There is no `digital` checkout method in 2026-04-08. Virtual SKUs **omit** checkout `fulfillment`. `digital` is used on the **order** resource: `fulfillment.expectations[].method_type`.

Catalog variants include required `description.plain` (Woo short description / description, or the product title) and `metadata.woocommerce` `{ virtual, downloadable, needs_shipping }`.

Cart and checkout share one Woo order. Cart id = checkout session id = Woo `order_key`. After `create_checkout` with `cart_id`, `GET /carts/{id}` still works until the cart is canceled. `GET /orders/{id}` uses the Woo order id from `checkout.order.id` (order key is also accepted).

---

## Settings

| Option | Default | Meaning |
|---|---|---|
| `ucp_enabled` | `no` | Serves `/.well-known/ucp` and `/wp-json/ucp/v1` |
| `ucp_max_amount` | empty | Optional digits-only `x402.max_amount` in the discovery handler |

Uninstall still deletes `ax402_wc_settings` (covers the new keys). The profile is cached 5 minutes and busted on settings save.

---

## Classes (modular seams)

| Class | Role |
|---|---|
| `Ax402_WC_Ucp_Discovery` | Rewrite `/.well-known/ucp` |
| `Ax402_WC_Ucp_Profile_Builder` | Discovery JSON from enabled tokens |
| `Ax402_WC_Ucp_Rest_Controller` | Routes, `ucp_enabled` gate |
| `Ax402_WC_Ucp_Catalog` | Search / lookup / get_product |
| `Ax402_WC_Ucp_Cart` | Create / get / update / cancel cart |
| `Ax402_WC_Ucp_Checkout` | Create / get / update / cancel; `cart_id` conversion; deferred `prepare()` |
| `Ax402_WC_Ucp_Order` | `GET /orders/{id}` after payment |
| `Ax402_WC_Ucp_Fulfillment` | Woo rates / local pickup ↔ UCP fulfillment |
| `Ax402_WC_Ucp_Lines` | Shared line-item and buyer writes |
| `Ax402_WC_Ucp_Mapper` | `WC_Order` → UCP JSON (leak-checked) |
| `Ax402_WC_Ucp_Money` | USD → cents (exact vs ceil) |
| `Ax402_WC_Ucp_Status` | Pure status machine |
| `Ax402_WC_Ucp_Asset_Match` | Instrument → settlement option |
| `Ax402_WC_Ucp_Complete` | 402 relay + signature proxy + poll |
| `Ax402_WC_Ucp_Gateway_Http` | Allowlisted gateway HTTP (UCP only) |
| `Ax402_WC_Ucp_Mcp` | JSON-RPC MCP adapter (`initialize`, `tools/list`, `tools/call`, OpenRPC aliases) |
| `Ax402_WC_Ucp_Mcp_Payment` | MCP x402 wire: PaymentRequired on `structuredContent` (+ nested `payment_required`), `_meta["x402/payment"]` on retry |
| `Ax402_WC_Ucp_Leak` | Facilitator-string scanner for tests |

Human-flow classes (`class-pay-page.php`, Blocks, `class-gateway-proxy-controller.php`, legacy `class-agent-rest-controller.php`) are not rewritten. The only shared-pipeline change is **additive** FX metadata on settlement options.

---

## Testing

```bash
npm run test:unit          # includes UCP money, status, profile, leak, asset match
npm run test:e2e-ucp       # needs wp-env, UCP enabled, public WP_BASE_URL, buyer key
```

Unit tests do not boot WordPress. They lock the protocol rules (cents, discovery shape, no gateway host in JSON, 402 header forwarding). Live settlement is the E2E script.

Seed a shippable SKU `ax402-ship-box` plus a US flat-rate zone for the physical path. Virtual path uses `ax402-micropay` ($0.01). Sub-cent SKUs (`ax402-signal`, `ax402-dust`) must **not** appear in UCP search.

Set `AX402_UCP_ENABLED=yes` in `.env` and re-run `npm run env:e2e` so seed writes `ucp_enabled`.

Out of this version: discount capability, A2A, HTTP Message Signatures, rewriting x402 `resource` to the shop URL.
