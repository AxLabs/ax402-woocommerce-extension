/**
 * Smoke the Shopify `ucp` CLI against this shop (no wallet / no SAW).
 *
 * Requires:
 * - `ucp` on PATH (https://github.com/Shopify/ucp-cli)
 * - UCP enabled (on after seed unless AX402_UCP_ENABLED=no)
 * - WP_BASE_URL — HTTPS origin the CLI will use as --business (ngrok/cloudflare)
 *
 * Walks discover → catalog search → --input-schema → cart → checkout →
 * shipping update → complete (expects payment_required, not a paid order).
 *
 * Skip (exit 0) when `ucp` is missing so CI unit jobs stay green.
 */
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

loadDotEnv();

const business = (process.env.WP_BASE_URL || '').replace(/\/$/, '');
const physical = process.env.E2E_UCP_PHYSICAL === '1';

function loadDotEnv() {
  const path = new URL('../../.env', import.meta.url);
  let text = '';
  try {
    text = readFileSync(path, 'utf8');
  } catch {
    return;
  }
  for (const line of text.split('\n')) {
    const trimmed = line.trim();
    if (trimmed === '' || trimmed.startsWith('#')) {
      continue;
    }
    const eq = trimmed.indexOf('=');
    if (eq < 1) {
      continue;
    }
    const key = trimmed.slice(0, eq).trim();
    let val = trimmed.slice(eq + 1).trim();
    if (
      (val.startsWith('"') && val.endsWith('"'))
      || (val.startsWith("'") && val.endsWith("'"))
    ) {
      val = val.slice(1, -1);
    }
    if (process.env[key] === undefined) {
      process.env[key] = val;
    }
  }
}

function whichUcp() {
  const probe = spawnSync('ucp', ['--version'], { encoding: 'utf8' });
  if (probe.error && probe.error.code === 'ENOENT') {
    return false;
  }
  return probe.status === 0 || probe.status === 1;
}

function ucp(args, opts = {}) {
  const extra = [];
  if (business.includes('ngrok')) {
    extra.push('--header', 'ngrok-skip-browser-warning: 1');
  }
  const result = spawnSync('ucp', [...args, ...extra], {
    encoding: 'utf8',
    maxBuffer: 4 * 1024 * 1024,
    env: process.env,
  });
  const stdout = result.stdout || '';
  const stderr = result.stderr || '';
  if (opts.allowFail) {
    return { result, stdout, stderr };
  }
  if (result.status !== 0) {
    throw new Error(
      `ucp ${args.join(' ')} exited ${result.status}\n${stderr}\n${stdout}`
    );
  }
  return { result, stdout, stderr };
}

function parseJson(text, label) {
  const start = text.indexOf('{');
  const end = text.lastIndexOf('}');
  if (start < 0 || end <= start) {
    throw new Error(`${label}: no JSON object in output:\n${text}`);
  }
  try {
    return JSON.parse(text.slice(start, end + 1));
  } catch (err) {
    throw new Error(`${label}: JSON parse failed: ${err.message}\n${text}`);
  }
}

function resultOf(envelope) {
  return envelope.result || envelope;
}

async function main() {
  if (!whichUcp()) {
    console.log('skip: ucp CLI not on PATH (see https://github.com/Shopify/ucp-cli)');
    process.exit(0);
  }
  if (!business) {
    console.error('WP_BASE_URL is required (HTTPS origin for --business)');
    process.exit(2);
  }
  if (!business.startsWith('https://')) {
    console.error(`WP_BASE_URL must be https for the UCP CLI (got ${business})`);
    process.exit(2);
  }

  ucp(['profile', 'init', '--name', 'agent'], { allowFail: true });

  const discovered = parseJson(
    ucp(['discover', '--business', business, '--format', 'json']).stdout,
    'discover'
  );
  const shopping = discovered?.ucp?.services?.['dev.ucp.shopping']
    || discovered?.result?.ucp?.services?.['dev.ucp.shopping'];
  if (!Array.isArray(shopping) && shopping == null) {
    console.log('discover keys', Object.keys(discovered));
  }

  const schemaOut = ucp([
    'checkout',
    'update',
    '--input-schema',
    '--business',
    business,
    '--format',
    'json',
  ]).stdout;
  const schema = parseJson(schemaOut, 'checkout update --input-schema');
  const schemaText = JSON.stringify(schema);
  if (!schemaText.includes('street_address') || !schemaText.includes('address_country')) {
    throw new Error(`update_checkout input schema missing postal fields: ${schemaText.slice(0, 800)}`);
  }
  console.log('input-schema has street_address + address_country');

  const query = physical ? 'Ship Box' : 'Micropay';
  const search = parseJson(
    ucp([
      'catalog',
      'search',
      '--business',
      business,
      '--input',
      JSON.stringify({ query, pagination: { limit: 10 } }),
      '--format',
      'json',
    ]).stdout,
    'catalog search'
  );
  const products = resultOf(search).products || [];
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
    throw new Error(`no catalog item for ${skuHint}`);
  }
  console.log('catalog item', itemId);

  const cart = parseJson(
    ucp([
      'cart',
      'create',
      '--business',
      business,
      '--input',
      JSON.stringify({
        line_items: [{ item: { id: String(itemId) }, quantity: 1 }],
        context: { address_country: physical ? 'US' : 'CH' },
      }),
      '--format',
      'json',
    ]).stdout,
    'cart create'
  );
  const cartId = resultOf(cart).id;
  if (!cartId) {
    throw new Error(`cart missing id: ${JSON.stringify(cart)}`);
  }
  console.log('cart', cartId);

  const created = parseJson(
    ucp([
      'checkout',
      'create',
      '--business',
      business,
      '--input',
      JSON.stringify({ cart_id: cartId, line_items: [] }),
      '--format',
      'json',
    ]).stdout,
    'checkout create'
  );
  let session = resultOf(created);
  const sessionId = session.id;
  if (!sessionId) {
    throw new Error(`checkout missing id: ${JSON.stringify(created)}`);
  }
  console.log('checkout', sessionId, session.status);

  if (physical) {
    const updated = parseJson(
      ucp([
        'checkout',
        'update',
        sessionId,
        '--business',
        business,
        '--input',
        JSON.stringify({
          line_items: [{ item: { id: String(itemId) }, quantity: 1 }],
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
        '--format',
        'json',
      ]).stdout,
      'checkout update'
    );
    session = resultOf(updated);
    const optionId = session.fulfillment?.methods?.[0]?.groups?.[0]?.options?.[0]?.id;
    if (!optionId) {
      throw new Error(`no shipping options: ${JSON.stringify(session.fulfillment)}`);
    }
    const selected = parseJson(
      ucp([
        'checkout',
        'update',
        sessionId,
        '--business',
        business,
        '--input',
        JSON.stringify({
          line_items: [{ item: { id: String(itemId) }, quantity: 1 }],
          fulfillment: {
            methods: [
              {
                type: 'shipping',
                selected_destination_id: session.fulfillment.methods[0].selected_destination_id,
                destinations: session.fulfillment.methods[0].destinations,
                groups: [{ id: session.fulfillment.methods[0].groups[0].id, selected_option_id: optionId }],
              },
            ],
          },
        }),
        '--format',
        'json',
      ]).stdout,
      'checkout select rate'
    );
    session = resultOf(selected);
  }

  const completeRun = ucp([
    'checkout',
    'complete',
    sessionId,
    '--business',
    business,
    '--format',
    'json',
  ], { allowFail: true });
  const complete = parseJson(
    completeRun.stdout || completeRun.stderr,
    'checkout complete'
  );
  const after = resultOf(complete);
  const status = after.status || after.ucp?.status;
  const messages = after.messages || [];
  const paymentish = JSON.stringify(after).includes('payment_required')
    || messages.some((m) => m?.code === 'payment_required')
    || status === 'ready_for_complete';
  if (status === 'completed') {
    throw new Error('complete without x402 settled the order; expected payment_required');
  }
  if (!paymentish && status !== 'incomplete' && status !== 'ready_for_complete') {
    throw new Error(`unexpected complete result: ${JSON.stringify(after).slice(0, 1200)}`);
  }
  console.log('complete without payment →', status || 'payment_required');
  console.log('ucp CLI smoke ok');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
