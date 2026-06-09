import { test, expect } from '@playwright/test';
import { resetDatabase } from './helpers/reset';
import { login } from './helpers/auth';

/**
 * Smoke 2: after login, open the task list, drill into a known task and assert
 * its status badge renders. Anchored on the FullDemoSeeder task
 * "Demo · Live deployment", which is pinned to workflow_status = InReview
 * ("In Review"). The list is paginated, so we use the searchable name column to
 * locate the row instead of relying on it being on the first page.
 */
test.beforeEach(() => {
  resetDatabase('FullDemoSeeder');
});

test('view task page renders status badge', async ({ page }) => {
  await login(page);

  await page.goto('/admin/tasks');

  // Narrow the (paginated) table down to the anchor task via the search box.
  const search = page.getByRole('searchbox').first();
  await search.fill('Demo · Live deployment');
  const row = page.getByRole('link', { name: 'Demo · Live deployment' });
  await expect(row.first()).toBeVisible();

  await row.first().click();
  await expect(page).toHaveURL(/\/admin\/tasks\/[^/]+/);

  // The InReview status badge is rendered on the task detail (default locale en).
  await expect(page.locator('body')).toContainText('In Review');
});
