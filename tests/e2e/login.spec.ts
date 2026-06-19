import { test, expect } from '@playwright/test';

/**
 * Smoke-Test 1: Filament-Admin login funktioniert.
 *
 * Voraussetzung: DemoSeeder gelaufen — `.tools/bin/dev-reset.sh` oder
 * `php artisan db:seed --class=DemoSeeder`. Default-Login:
 *   email:    admin@argos.local  (SEED_USER_EMAIL)
 *   password: 12345              (ADMIN_PASSWORD default)
 */
test('admin can log in and reach the dashboard', async ({ page }) => {
  await page.goto('/admin/login');

  await page.getByRole('textbox', { name: /email/i }).fill('admin@argos.local');
  await page.getByRole('textbox', { name: /password/i }).fill('12345');
  await page.getByRole('button', { name: /sign in/i }).click();

  // Wait until we have actually LEFT the login page. A bare /\/admin/ match
  // resolves immediately against /admin/login itself, so a broken login (e.g. a
  // session cookie the browser can't return over the test's http/127.0.0.1
  // origin) would still pass — assert the navigation away instead.
  await page.waitForURL((url) => /\/admin(\/|$)/.test(url.pathname) && !/\/admin\/login/.test(url.pathname));
  await expect(page.locator('body')).not.toContainText(/these credentials/i);
});
