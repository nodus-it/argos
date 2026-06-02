import { type Page } from '@playwright/test';

/**
 * Log into the Filament admin panel. Selectors match the working smoke specs.
 * Default credentials are the seeded admin (AdminUserSeeder / ADMIN_PASSWORD).
 */
export async function login(
  page: Page,
  email = 'admin@argos.local',
  password = '12345',
): Promise<void> {
  await page.goto('/admin/login');
  await page.getByRole('textbox', { name: /email/i }).fill(email);
  await page.getByRole('textbox', { name: /password/i }).fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(/\/admin/);
}
