import { type Page } from '@playwright/test';

/**
 * Log into Argos (lands on My work, `/`). Selectors match the working smoke specs.
 * Default credentials are the seeded admin (AdminUserSeeder / ADMIN_PASSWORD).
 */
export async function login(
  page: Page,
  email = 'admin@argos.local',
  password = '12345',
): Promise<void> {
  await page.goto('/login');
  await page.getByRole('textbox', { name: /email/i }).fill(email);
  await page.getByRole('textbox', { name: /password/i }).fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  // The Livewire auth round-trip keeps us on /login until it succeeds. Wait
  // until we have actually left the login page (dashboard or onboarding).
  await page.waitForURL((url) => url.pathname !== '/login');
  await page.waitForLoadState('networkidle');
}
