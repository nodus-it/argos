import { test, expect } from '@playwright/test';

/**
 * Smoke-Test 2: nach Login die Task-Liste öffnen, ersten Task anschauen,
 * Status-Badge prüfen. Greift gegen das vom DemoSeeder erzeugte Draft-Task.
 */
test('view task page renders status badge', async ({ page }) => {
  // Login
  await page.goto('/admin/login');
  await page.getByRole('textbox', { name: /email/i }).fill('admin@argos.local');
  await page.getByRole('textbox', { name: /password/i }).fill('12345');
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(/\/admin/);
  await page.waitForLoadState('networkidle');

  // Task-Liste
  await page.goto('/admin/tasks');
  await expect(page.locator('body')).toContainText('Demo task');

  // Ersten Task öffnen (DemoSeeder legt genau einen an, Status Draft)
  await page.getByRole('link', { name: 'Demo task' }).first().click();
  await expect(page).toHaveURL(/\/admin\/tasks\/[^/]+/);

  // Draft-Badge sichtbar (Default-Locale ist en)
  await expect(page.locator('body')).toContainText('Draft');
});
