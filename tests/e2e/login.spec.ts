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

  await expect(page).toHaveURL(/\/admin/);
  await expect(page.locator('body')).not.toContainText(/these credentials/i);
});
