import { test, expect } from '@playwright/test';

test.beforeEach(async ({ page }) => {
  await page.goto('/');
  await page.getByLabel('Username').fill('admin');
  await page.getByLabel('Password').fill('admin123');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('heading', { name: 'Products' })).toBeVisible();
});

test('dragging a testimonial row updates the displayed sort order for both rows', async ({ page }) => {
  // HR landing for abforge: seeded with exactly 2 own (non-inherited) testimonials.
  await page.goto('/#/landings/61766');
  const rows = page.locator('#testimonials-table tbody tr[data-id]');
  await expect(rows).toHaveCount(2);

  const authorBefore = [await rows.nth(0).locator('td').nth(3).innerText(), await rows.nth(1).locator('td').nth(3).innerText()];
  expect(authorBefore[0]).not.toBe(authorBefore[1]);

  // Native HTML5 drag-and-drop (draggable="true" + dragstart/dragover/drop) isn't reliably
  // triggered by Playwright's mouse-simulation-based dragTo(), especially here where our own
  // dragover handler live-reorders the DOM mid-gesture. Dispatch the same event sequence the
  // browser would fire directly instead.
  await page.evaluate(() => {
    const tbody = document.querySelector('#testimonials-table tbody')!;
    const [first, second] = [...tbody.querySelectorAll('tr[data-id]')];
    first.dispatchEvent(new Event('dragstart', { bubbles: true }));
    const rect = second.getBoundingClientRect();
    const overEvt = new Event('dragover', { bubbles: true, cancelable: true });
    Object.defineProperty(overEvt, 'clientY', { value: rect.bottom - 2 });
    second.dispatchEvent(overEvt);
    second.dispatchEvent(new Event('drop', { bubbles: true, cancelable: true }));
    first.dispatchEvent(new Event('dragend', { bubbles: true }));
  });
  await expect(page.getByText('Order saved')).toBeVisible();

  const reordered = page.locator('#testimonials-table tbody tr[data-id]');
  await expect(reordered.nth(0)).toContainText(authorBefore[1]);
  await expect(reordered.nth(1)).toContainText(authorBefore[0]);

  // Regression check: the displayed "#" (sort_order) must match the NEW position, not
  // stay frozen at each row's original value from before the drag.
  await expect(reordered.nth(0).locator('td').nth(2)).toHaveText('0');
  await expect(reordered.nth(1).locator('td').nth(2)).toHaveText('1');
});
