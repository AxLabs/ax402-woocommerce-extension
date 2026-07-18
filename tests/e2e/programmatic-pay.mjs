/**
 * Programmatic agent pay E2E.
 *
 * Requires:
 * - AX402_API_KEY, AX402_PAY_TO_ADDRESS
 * - AX402_EVM_PRIVATE_KEY (funded Base Sepolia USDC)
 * - WP_BASE_URL publicly reachable by Ax402 gateway (tunnel or staging)
 *
 * Flow: POST /wp-json/ax402/v1/orders → buyer pay gateway URL → GET order status paid
 */
import { buyerClientFromEnv } from '@ax402/sdk/buyer';

const wpBase = (process.env.WP_BASE_URL || '').replace(/\/$/, '');
const productId = process.env.E2E_PRODUCT_ID || '';

function requireEnv(name) {
  const v = process.env[name];
  if (!v) {
    console.error(`Missing ${name}`);
    process.exit(2);
  }
  return v;
}

async function main() {
  if (!wpBase) {
    console.error('WP_BASE_URL is required (must be public for gateway upstream)');
    process.exit(2);
  }
  requireEnv('AX402_EVM_PRIVATE_KEY');

  let pid = productId;
  if (!pid) {
    const catalog = await fetch(`${wpBase}/wp-json/ax402/v1/products`).then((r) => r.json());
    pid = String(catalog?.products?.[0]?.id || '');
  }
  if (!pid) {
    console.error('No product available');
    process.exit(1);
  }

  const created = await fetch(`${wpBase}/wp-json/ax402/v1/orders`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      line_items: [{ product_id: Number(pid), quantity: 1 }],
      billing_email: 'agent@example.com',
    }),
  }).then(async (r) => {
    const body = await r.json();
    if (!r.ok) {
      throw new Error(`create order failed: ${r.status} ${JSON.stringify(body)}`);
    }
    return body;
  });

  console.log('created order', created.order_key, created.payment_url);

  const buyer = await buyerClientFromEnv();
  const { response } = await buyer.pay({ url: created.payment_url });
  console.log('pay status', response.status);

  const status = await fetch(created.status_url).then((r) => r.json());
  console.log('order status', status);
  if (!status.paid) {
    process.exit(1);
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
