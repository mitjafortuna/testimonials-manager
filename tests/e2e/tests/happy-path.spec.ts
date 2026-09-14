import { test, expect, Page } from '@playwright/test';

const PNG_1x1 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');

async function login(page: Page) {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await page.getByLabel('Username').fill('admin');
  await page.getByLabel('Password').fill('admin123');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('heading', { name: 'Products' })).toBeVisible();
}

test('editor can find a product, pick a country and manage a testimonial with an image', async ({ page }) => {
  await login(page);

  // 1. search
  await page.getByLabel('Search products').fill('abforge');
  await page.getByRole('button', { name: 'Search' }).click();
  const row = page.locator('tr.tm-row-link', { hasText: 'abforge' });
  await expect(row).toHaveCount(1);
  await row.click();

  // 2. country overview → a localised landing that inherits from EN
  await expect(page.locator('.tm-country-card')).not.toHaveCount(0);
  const inheriting = page.locator('.tm-country-card', { hasText: 'inherits EN' }).first();
  await expect(inheriting).toBeVisible();
  const country = (await inheriting.locator('.tm-country-code').textContent())?.trim();
  await inheriting.click();
  await expect(page.getByText('Inherited from the English master')).toBeVisible();

  // 3. create
  await page.getByRole('button', { name: 'Add testimonial' }).click();
  const modal = page.locator('#testimonial-modal');
  await modal.getByLabel('Author name').fill('E2E Author');
  await modal.getByLabel('Text').fill('Written by Playwright.');
  await modal.getByLabel('Rating').selectOption('5');
  await modal.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Testimonial created')).toBeVisible();
  await expect(page.getByText('Inherited from the English master')).toHaveCount(0);
  const created = page.locator('#testimonials-table tr', { hasText: 'E2E Author' });
  await expect(created).toHaveCount(1);
  await expect(created).toContainText('★ 5.0');

  // 4. edit + upload an image
  await created.getByRole('button', { name: 'Edit' }).click();
  await modal.getByLabel('Author name').fill('E2E Author Edited');
  await modal.locator('#iu-input').setInputFiles({ name: 'dot.png', mimeType: 'image/png', buffer: PNG_1x1 });
  await expect(modal.locator('.tm-img-card')).toHaveCount(1);
  await expect(page.getByText('1 image uploaded')).toBeVisible();
  await modal.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Testimonial saved')).toBeVisible();
  const edited = page.locator('#testimonials-table tr', { hasText: 'E2E Author Edited' });
  await expect(edited.locator('img.tm-thumb')).toHaveCount(1);

  // 5. inline active toggle shows explicit save state
  await edited.locator('input.tm-active').click();
  await expect(edited.locator('.tm-save-status')).toContainText('Saved');

  // 6. delete with confirmation
  await edited.locator('.tm-delete').click();
  await expect(page.locator('#confirm-modal')).toBeVisible();
  await page.locator('#confirm-ok').click();
  await expect(page.getByText('Testimonial deleted')).toBeVisible();
  await expect(page.locator('#testimonials-table tr', { hasText: 'E2E Author' })).toHaveCount(0);
  expect(country).toBeTruthy();
});
