/**
 * UCP agent E2E: discover → search → cart → checkout → MCP 402 → pay → GET order.
 *
 * Requires:
 * - AX402_UCP_ENABLED=yes (re-seed with npm run env:e2e)
 * - AX402_API_KEY, AX402_PAY_TO_ADDRESS
 * - AX402_EVM_PRIVATE_KEY
 * - WP_BASE_URL publicly reachable by the Ax402 gateway
 *
 * Optional:
 * - E2E_UCP_PHYSICAL=1  use ax402-ship-box + a US shipping address
 * - E2E_UCP_TRANSPORT=rest  pay via REST complete headers instead of MCP
 *
 * Default complete hop is MCP (structured payment_required + _meta retry).
 * The signed x402 resource is still the Ax402 gateway URL (min-leak adapter).
 * See docs/ucp.md.
 */
import { buyerClientFromEnv } from '@ax402/sdk/buyer';

const wpBase = (process.env.WP_BASE_URL || '').replace(/\/$/, '');
const physical = process.env.E2E_UCP_PHYSICAL === '1';
const transport = (process.env.E2E_UCP_TRANSPORT || 'mcp').toLowerCase();
const agentProfile = 'https://ax402.example/e2e-agent';
let mcpSeq = 1;

function requireEnv(name) {
  const v = process.env[name];
  if (!v) {
    console.error(`Missing ${name}`);
    process.exit(2);
  }
  return v;
}

async function jsonFetch(url, init = {}) {
  const res = await fetch(url, {
    ...init,
    headers: {
      'Content-Type': 'application/json',
      'UCP-Agent': `profile="${agentProfile}"`,
      ...(init.headers || {}),
    },
  });
  const text = await res.text();
  let body = null;
  try {
    body = text ? JSON.parse(text) : null;
  } catch {
    body = { raw: text };
  }
  return { res, body };
}

async function mcpRpc(endpoint, method, params = {}) {
  const { res, body } = await jsonFetch(endpoint, {
    method: 'POST',
    body: JSON.stringify({
      jsonrpc: '2.0',
      id: mcpSeq++,
      method,
      params,
    }),
  });
  if (body?.error) {
    throw new Error(`MCP ${method} failed: ${JSON.stringify(body.error)}`);
  }
  return { res, body };
}

async function mcpTool(endpoint, name, args, extra = {}) {
  const params = { name, arguments: args };
  if (extra._meta) {
    params._meta = extra._meta;
  }
  return mcpRpc(endpoint, 'tools/call', params);
}

function paymentResourceUrl(paymentRequired) {
  if (!paymentRequired || typeof paymentRequired !== 'object') {
    return '';
  }
  if (typeof paymentRequired.resource === 'string') {
    return paymentRequired.resource;
  }
  if (paymentRequired.resource && typeof paymentRequired.resource.url === 'string') {
    return paymentRequired.resource.url;
  }
  return '';
}

async function capturePaymentSignature(resourceUrl) {
  const buyer = await buyerClientFromEnv();
  const captured = { signature: '', data: '' };
  const inner = globalThis.fetch.bind(globalThis);
  globalThis.fetch = async (input, init = {}) => {
    const method = String(init.method || 'GET').toUpperCase();
    const headers = new Headers(init.headers || {});
    const sig = headers.get('payment-signature')
      || headers.get('PAYMENT-SIGNATURE')
      || headers.get('x-payment')
      || '';
    if (sig && method === 'POST') {
      captured.signature = sig;
      captured.data = headers.get('payment-signature-data')
        || headers.get('PAYMENT-SIGNATURE-DATA')
        || '';
      return new Response('{}', {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }
    return inner(input, init);
  };
  try {
    await buyer.pay({ url: resourceUrl });
  } catch (err) {
    if (!captured.signature) {
      throw err;
    }
  } finally {
    globalThis.fetch = inner;
  }
  return captured;
}

async function main() {
  if (!wpBase) {
    console.error('WP_BASE_URL is required (must be public for gateway upstream)');
    process.exit(2);
  }
  requireEnv('AX402_EVM_PRIVATE_KEY');

  const discovery = await jsonFetch(`${wpBase}/.well-known/ucp`);
  if (!discovery.res.ok) {
    throw new Error(`discovery failed: ${discovery.res.status} ${JSON.stringify(discovery.body)}`);
  }
  const shopping = discovery.body?.ucp?.services?.['dev.ucp.shopping'] || [];
  const rest = shopping.find((s) => s.transport === 'rest') || shopping[0];
  const mcp = shopping.find((s) => s.transport === 'mcp');
  const endpoint = rest?.endpoint;
  const mcpEndpoint = mcp?.endpoint;
  if (!endpoint) {
    throw new Error('discovery missing REST services.dev.ucp.shopping endpoint — is UCP enabled?');
  }
  if (!discovery.body?.ucp?.capabilities?.['dev.ucp.shopping.cart']
    || !discovery.body?.ucp?.capabilities?.['dev.ucp.shopping.order']) {
    throw new Error('discovery missing cart/order capabilities — save Ax402 settings to bust the profile cache');
  }
  const handler = discovery.body?.ucp?.payment_handlers?.['org.x402.payment'];
  if (!Array.isArray(handler) || !handler[0]?.x402) {
    throw new Error('discovery missing org.x402.payment handler');
  }
  console.log('discovery endpoint', endpoint, 'mcp', mcpEndpoint || '(none)');

  const query = physical ? 'Ship Box' : 'Micropay';
  const search = await jsonFetch(`${endpoint}/catalog/search`, {
    method: 'POST',
    body: JSON.stringify({ query, pagination: { limit: 10 } }),
  });
  if (!search.res.ok) {
    throw new Error(`search failed: ${search.res.status} ${JSON.stringify(search.body)}`);
  }
  const products = search.body?.products || [];
  const ids = products.map((p) => p.id);
  if (ids.some((id) => !id)) {
    throw new Error('search returned a product without id');
  }
  // Sub-cent demo SKUs must never appear as UCP catalog prices.
  for (const product of products) {
    const amount = product?.price_range?.min?.amount;
    if (amount !== undefined && (!Number.isInteger(amount) || amount < 1)) {
      throw new Error(`non-cent price in UCP catalog: ${JSON.stringify(product.price_range)}`);
    }
    const variant = product.variants?.[0];
    if (!variant?.description?.plain) {
      throw new Error(`variant missing description.plain: ${JSON.stringify(variant)}`);
    }
  }

  const skuHint = physical ? 'ax402-ship-box' : 'ax402-micropay';
  let itemId = '';
  for (const product of products) {
    const variant = product.variants?.[0];
    if (variant?.sku === skuHint || product.title?.includes(physical ? 'Ship Box' : 'Micropay')) {
      itemId = variant?.id || product.id;
      break;
    }
  }
  if (!itemId) {
    itemId = products[0]?.variants?.[0]?.id || products[0]?.id || '';
  }
  if (!itemId) {
    throw new Error(`no UCP catalog item for ${skuHint}`);
  }
  console.log('catalog item', itemId);

  const cart = await jsonFetch(`${endpoint}/carts`, {
    method: 'POST',
    body: JSON.stringify({
      line_items: [{ item: { id: String(itemId) }, quantity: 1 }],
      buyer: { email: 'ucp-agent@example.com', first_name: 'UCP', last_name: 'Agent' },
    }),
  });
  if (cart.res.status !== 201) {
    throw new Error(`create cart failed: ${cart.res.status} ${JSON.stringify(cart.body)}`);
  }
  const cartId = cart.body?.id;
  if (!cartId) {
    throw new Error(`cart missing id: ${JSON.stringify(cart.body)}`);
  }
  console.log('cart', cartId);

  const created = await jsonFetch(`${endpoint}/checkout-sessions`, {
    method: 'POST',
    body: JSON.stringify({ cart_id: cartId }),
  });
  if (created.res.status !== 201) {
    throw new Error(`create checkout from cart failed: ${created.res.status} ${JSON.stringify(created.body)}`);
  }
  let session = created.body;
  const sessionId = session.id;
  console.log('session', sessionId, session.status);

  if (physical) {
    const updated = await jsonFetch(`${endpoint}/checkout-sessions/${sessionId}`, {
      method: 'PUT',
      body: JSON.stringify({
        line_items: [{ item: { id: String(itemId) }, quantity: 1 }],
        buyer: { email: 'ucp-agent@example.com', first_name: 'UCP', last_name: 'Agent' },
        fulfillment: {
          methods: [
            {
              type: 'shipping',
              destinations: [
                {
                  street_address: '1 Market St',
                  address_locality: 'San Francisco',
                  address_region: 'CA',
                  postal_code: '94105',
                  address_country: 'US',
                },
              ],
            },
          ],
        },
      }),
    });
    if (!updated.res.ok) {
      throw new Error(`address update failed: ${updated.res.status} ${JSON.stringify(updated.body)}`);
    }
    session = updated.body;
    const optionId = session.fulfillment?.methods?.[0]?.groups?.[0]?.options?.[0]?.id;
    if (!optionId) {
      throw new Error(`no shipping options: ${JSON.stringify(session.fulfillment)}`);
    }
    const selected = await jsonFetch(`${endpoint}/checkout-sessions/${sessionId}`, {
      method: 'PUT',
      body: JSON.stringify({
        line_items: [{ item: { id: String(itemId) }, quantity: 1 }],
        buyer: { email: 'ucp-agent@example.com', first_name: 'UCP', last_name: 'Agent' },
        fulfillment: {
          methods: [
            {
              type: 'shipping',
              selected_destination_id: session.fulfillment.methods[0].selected_destination_id,
              destinations: session.fulfillment.methods[0].destinations,
              groups: [{ id: 'package_1', selected_option_id: optionId }],
            },
          ],
        },
      }),
    });
    if (!selected.res.ok) {
      throw new Error(`shipping select failed: ${selected.res.status} ${JSON.stringify(selected.body)}`);
    }
    session = selected.body;
    const types = (session.totals || []).map((t) => t.type);
    if (!types.includes('fulfillment') && !types.includes('total')) {
      throw new Error(`expected totals after shipping: ${JSON.stringify(session.totals)}`);
    }
    console.log('shipping selected', optionId, 'totals', session.totals);
  }

  if (!physical) {
    const methods = session.fulfillment?.methods || [];
    if (methods.some((m) => m.type === 'digital')) {
      throw new Error(`checkout must not use type digital: ${JSON.stringify(session.fulfillment)}`);
    }
  }

  if (session.status !== 'ready_for_complete') {
    throw new Error(`expected ready_for_complete, got ${session.status} ${JSON.stringify(session.messages)}`);
  }

  const completeUrl = `${endpoint}/checkout-sessions/${sessionId}/complete`;
  const x402Instrument = {
    id: 'instr_x402_1',
    handler_id: 'org.x402.payment',
    type: 'x402',
    selected: true,
  };

  if (transport === 'rest') {
    const challenge = await jsonFetch(completeUrl, { method: 'POST', body: '{}' });
    if (challenge.res.status !== 402) {
      throw new Error(`expected 402, got ${challenge.res.status} ${JSON.stringify(challenge.body)}`);
    }
    const paymentRequired = challenge.res.headers.get('payment-required')
      || challenge.res.headers.get('PAYMENT-REQUIRED');
    if (!paymentRequired) {
      throw new Error('402 missing PAYMENT-REQUIRED header');
    }
    console.log('REST 402 challenge ok');

    const buyer = await buyerClientFromEnv();
    const payResult = await buyer.pay({
      url: completeUrl,
      method: 'POST',
      body: JSON.stringify({ payment: { instruments: [x402Instrument] } }),
    });
    console.log('pay status', payResult.response?.status ?? payResult.status ?? payResult);
  } else {
    if (!mcpEndpoint) {
      throw new Error('discovery missing MCP endpoint');
    }
    const init = await mcpRpc(mcpEndpoint, 'initialize', {
      protocolVersion: '2025-03-26',
      capabilities: {},
      clientInfo: { name: 'ax402-ucp-e2e', version: '0.2.0' },
    });
    if (!init.body?.result?.serverInfo?.name) {
      throw new Error(`MCP initialize failed: ${JSON.stringify(init.body)}`);
    }

    const agentMeta = {
      'ucp-agent': { profile: agentProfile },
      'idempotency-key': crypto.randomUUID(),
    };
    const challenge = await mcpTool(mcpEndpoint, 'complete_checkout', {
      meta: agentMeta,
      id: sessionId,
      checkout: {},
    });
    const structured = challenge.body?.result?.structuredContent;
    const paymentRequired = structured?.payment_required
      || challenge.body?.result?._meta?.['x402/payment-required'];
    if (!paymentRequired) {
      throw new Error(`MCP complete missing payment_required: ${JSON.stringify(challenge.body)}`);
    }
    const resourceUrl = paymentResourceUrl(paymentRequired);
    if (!resourceUrl) {
      throw new Error(`payment_required missing resource: ${JSON.stringify(paymentRequired)}`);
    }
    console.log('MCP 402 challenge ok');

    const captured = await capturePaymentSignature(resourceUrl);
    if (!captured.signature) {
      throw new Error('buyer did not produce PAYMENT-SIGNATURE');
    }
    const paid = await mcpTool(mcpEndpoint, 'complete_checkout', {
      meta: { ...agentMeta, 'idempotency-key': crypto.randomUUID() },
      id: sessionId,
      checkout: { payment: { instruments: [x402Instrument] } },
    }, {
      _meta: {
        'x402/payment': captured.signature,
        ...(captured.data ? { 'x402/payment-data': captured.data } : {}),
      },
    });
    const paidSession = paid.body?.result?.structuredContent;
    console.log('MCP pay status', paidSession?.status, paidSession?.order);
  }

  const got = await jsonFetch(`${endpoint}/checkout-sessions/${sessionId}`);
  if (!got.res.ok) {
    throw new Error(`GET session failed: ${got.res.status} ${JSON.stringify(got.body)}`);
  }
  console.log('session status', got.body.status, got.body.order);
  if (got.body.status !== 'completed') {
    process.exit(1);
  }
  const orderId = got.body.order?.id;
  if (!orderId) {
    throw new Error(`completed checkout missing order.id: ${JSON.stringify(got.body.order)}`);
  }
  const order = await jsonFetch(`${endpoint}/orders/${orderId}`);
  if (!order.res.ok) {
    throw new Error(`GET order failed: ${order.res.status} ${JSON.stringify(order.body)}`);
  }
  const methodTypes = (order.body.fulfillment?.expectations || []).map((e) => e.method_type);
  const expected = physical ? 'shipping' : 'digital';
  if (!methodTypes.includes(expected)) {
    throw new Error(`order expectations expected ${expected}, got ${JSON.stringify(order.body.fulfillment)}`);
  }
  console.log('order', order.body.id, 'expectations', methodTypes);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
