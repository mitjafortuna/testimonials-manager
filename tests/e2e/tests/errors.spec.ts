import { test, expect } from '@playwright/test';

test.beforeEach(async ({ page }) => {
  await page.goto('/');
  await page.getByLabel('Username').fill('admin');
  await page.getByLabel('Password').fill('admin123');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('heading', { name: 'Products' })).toBeVisible();
});

test('validation errors are shown on the fields, never swallowed', async ({ page }) => {
  await page.goto('/#/landings/61763');
  await page.getByRole('button', { name: 'Add testimonial' }).click();
  const modal = page.locator('#testimonial-modal');
  await modal.getByLabel('Link (URL)').fill('not a url');
  await modal.getByRole('button', { name: 'Save' }).click();
  await expect(modal.locator('#tf-author')).toHaveClass(/is-invalid/);
  await expect(modal.locator('#tf-text')).toHaveClass(/is-invalid/);
  await expect(modal.locator('#tf-url')).toHaveClass(/is-invalid/);
  await expect(modal.locator('#tf-status')).toContainText('Failed');
  await expect(modal).toBeVisible();
});

test('a server error while saving is visible and the row is not silently changed', async ({ page }) => {
  await page.goto('/#/landings/61763');
  const first = page.locator('#testimonials-table tr[data-id]').first();
  const toggle = first.locator('input.tm-active');
  const before = await toggle.isChecked();
  await page.route('**/api/testimonials/*', (route) =>
    route.request().method() === 'PATCH'
      ? route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ error: { code: 'internal_error', message: 'Simulated outage' } }) })
      : route.continue());
  await toggle.click();
  await expect(first.locator('.tm-save-status')).toContainText('Failed');
  await expect(page.locator('.toast', { hasText: 'Simulated outage' })).toBeVisible();
  expect(await toggle.isChecked()).toBe(before);
});

test('unauthenticated visitors are sent to the login page', async ({ page, context }) => {
  // The app checks auth once at boot and hash-only navigation is same-document, so a
  // goto() to the same URL the page is already on would not trigger a fresh boot.
  // Force a real reload to simulate a visitor loading the app without a session.
  await context.clearCookies();
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
});
