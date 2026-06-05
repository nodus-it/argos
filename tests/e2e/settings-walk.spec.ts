import { test, expect } from '@playwright/test';
import { resetDatabase } from './helpers/reset';
import { login } from './helpers/auth';

/**
 * Mask smoke: open every Filament resource/page and assert it renders without a
 * server error. Seeded with FullDemoSeeder so list pages have data to show.
 */
const PAGES: Array<{ label: string; path: string; expect?: RegExp }> = [
  { label: 'Dashboard', path: '/admin' },
  { label: 'Tasks', path: '/admin/tasks', expect: /Demo ·|Showcase/i },
  { label: 'Projects', path: '/admin/repo-profiles' },
  { label: 'API clients', path: '/admin/api-clients' },
  { label: 'Agent credentials', path: '/admin/agent-credentials' },
  { label: 'Worker stacks', path: '/admin/worker-stacks' },
  { label: 'Worker image builds', path: '/admin/worker-image-builds' },
  { label: 'Connected accounts', path: '/admin/connected-accounts' },
  { label: 'Settings', path: '/admin/settings' },
];

test.beforeEach(() => {
  resetDatabase('FullDemoSeeder');
});

test('every admin mask renders without error', async ({ page }) => {
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
