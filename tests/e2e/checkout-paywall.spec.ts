import { test, expect } from '@playwright/test';

/**
 * UI smoke: requires wp-env running with seeded products + configured gateway.
 * MetaMask signing is manual — this only asserts the pay page shell renders.
 */
test.describe('Ax402 pay page shell', () => {
  test.skip(!process.env.E2E_ORDER_KEY, 'Set E2E_ORDER_KEY to a pending Ax402 order key');

  test('renders pay root', async ({ page }) => {
    const key = process.env.E2E_ORDER_KEY as string;
    await page.goto(`/checkout/?ax402_pay=1&key=${encodeURIComponent(key)}`);
    await expect(page.locator('#ax402-pay-root')).toBeVisible();
    await expect(page.getByRole('heading', { name: /Ax402 payment/i })).toBeVisible();
  });
});
