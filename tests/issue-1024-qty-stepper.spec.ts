import { test, expect, type Page } from '@playwright/test';

/**
 * Acme #1024 — the Qty spinner/ArrowUp moved by the input's `step` (0.0001),
 * so 1 became 1.0001 instead of 2. The inputs now use step="1" and normalise
 * floating-point noise on blur; decimals must stay typeable.
 *
 * This is a real-browser test on purpose: jsdom does not implement the native
 * number-input spinner, so a component test cannot exercise ArrowUp.
 */

async function login(page: Page) {
  await page.goto('/login');
  await page.locator('#email').fill('becky@acme.com');
  await page.locator('#password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 30_000 });
}

async function pickProduct(page: Page, query: string, name: RegExp) {
  // The trigger is a combobox-styled button; the search input lives in the popover.
  await page.getByRole('combobox').filter({ hasText: /Search name, SKU/ }).first().click();
  const input = page.getByPlaceholder(/Search name, SKU/).first();
  await input.fill(query);
  await page.getByRole('option', { name }).first().click({ timeout: 15_000 });
}

async function assertQtyStepper(page: Page) {
  const qty = page.locator('td[data-label="Qty"] input[type="number"]').first();
  await expect(qty).toBeVisible({ timeout: 15_000 });
  await expect(qty).toHaveAttribute('step', '1');

  // ArrowUp on 1 -> 2 (used to go to 1.0001).
  await qty.fill('1');
  await qty.press('ArrowUp');
  await expect(qty).toHaveValue('2');

  // Fractional typing survives, including blur (no spinner noise rewrite).
  await qty.fill('1.5');
  await expect(qty).toHaveValue('1.5');
  await qty.blur();
  await expect(qty).toHaveValue('1.5');

  // ArrowUp on a fraction snaps to the next whole step (browser step grid).
  await qty.press('ArrowUp');
  await expect(qty).toHaveValue('2');

  // ArrowDown below zero is blocked by min=0.
  await qty.fill('0.5');
  await qty.press('ArrowDown');
  await expect(qty).toHaveValue('0');
}

test('invoice line Qty arrows step by 1 and typed decimals survive (#1024)', async ({ page }) => {
  await login(page);
  await page.goto('/invoices/create');
  await pickProduct(page, 'F240115150', /Front Morcellator/);
  await assertQtyStepper(page);
});

test('vendor bill line Qty arrows step by 1 and typed decimals survive (#1024)', async ({ page }) => {
  await login(page);
  await page.goto('/accounting/vendors/bills/create');
  await page.getByText('Select vendor...').first().click();
  await page.getByPlaceholder('Type to search...').fill('AcuVu');
  await page.getByRole('option', { name: /AcuVu/ }).first().click({ timeout: 15_000 });
  await pickProduct(page, 'F02306', /Retrieval Bag/);
  await assertQtyStepper(page);
});
