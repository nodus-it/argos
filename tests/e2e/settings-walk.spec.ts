import { test, expect } from '@playwright/test';
import { resetDatabase } from './helpers/reset';
import { login } from './helpers/auth';

/**
 * Mask smoke: open every Filament resource/page and assert it renders without a
 * server error. Seeded with FullDemoSeeder so list pages have data to show.
 */
const PAGES: Array<{ label: string; path: string; expect?: RegExp }> = [
  { label: 'My work', path: '/' },
  { label: 'Workspaces', path: '/workspaces' },
  { label: 'Profile', path: '/account/profile' },
  { label: 'Claude subscriptions', path: '/account/agents' },
  { label: 'Connected accounts', path: '/account/connections' },
  { label: 'Administration', path: '/system/overview' },
  { label: 'Users', path: '/system/users' },
  { label: 'Access tokens', path: '/system/tokens' },
  { label: 'OAuth apps', path: '/system/oauth-apps' },
  { label: 'API clients', path: '/system/api-clients' },
  { label: 'Documentation', path: '/docs' },
];

test.beforeEach(() => {
  resetDatabase('FullDemoSeeder');
});

test('every account and administration mask renders without error', async ({ page }) => {
  await login(page);

  for (const entry of PAGES) {
    const response = await page.goto(entry.path);
    expect(response?.status(), `${entry.label} (${entry.path}) HTTP status`).toBeLessThan(400);

    // Filament renders a 500/exception page in the body rather than an HTTP 500
    // in local/debug — guard against that too.
    await expect(page.locator('body'), entry.label).not.toContainText(/Server Error|Whoops|Exception/i);

    if (entry.expect) {
      await expect(page.locator('body'), entry.label).toContainText(entry.expect);
    }
  }
});
