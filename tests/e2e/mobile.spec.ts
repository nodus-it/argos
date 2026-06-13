import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';
import { resetDatabase } from './helpers/reset';
import { gotoStable } from './helpers/filament';
import {
  assertNoHorizontalOverflow,
  assertNoElementXScroll,
  assertTapTarget,
  assertWithinViewport,
} from './helpers/mobile';

/**
 * P9 mobile verification gate — the "fertig" criterion from concepts/mobile.md.
 *
 * Runs on the `mobile` Playwright project only (375px chromium viewport with
 * touch; see playwright.config.ts). Walks the high-value on-the-go screens and
 * asserts:
 *   - no horizontal page overflow (the root scroller stays within the viewport),
 *   - the key actions are visible and meet the 44px touch-target minimum.
 *
 * Seeded with FullDemoSeeder so the lists, the masks and the task detail
 * (hero / thread / diff / respond) all have content to render. Offline/fake
 * stack — no tokens or worker needed, same as the rest of the gemockt suite.
 */

// List + admin masks that must stay overflow-free on a phone. Mirrors the
// desktop settings-walk plus the in-app docs viewer (concept overlap D).
const MASKS: { label: string; path: string }[] = [
  { label: 'Dashboard', path: '/admin' },
  { label: 'Tasks', path: '/admin/tasks' },
  { label: 'Projects', path: '/admin/repo-profiles' },
  { label: 'API clients', path: '/admin/api-clients' },
  { label: 'Agent credentials', path: '/admin/agent-credentials' },
  { label: 'Worker stacks', path: '/admin/worker-stacks' },
  { label: 'Worker image builds', path: '/admin/worker-image-builds' },
  { label: 'Connected accounts', path: '/admin/connected-accounts' },
  { label: 'Settings', path: '/admin/settings' },
  { label: 'Documentation', path: '/admin/docs' },
  // Forms must not break on mobile (Filament stacks grids to 1 column below lg).
  { label: 'Create task', path: '/admin/tasks/create' },
  { label: 'Create project', path: '/admin/repo-profiles/create' },
];

test.describe('mobile gate @375px', () => {
  test.beforeEach(() => {
    resetDatabase('FullDemoSeeder');
  });

  test('list & admin masks have no horizontal overflow', async ({ page }) => {
    await login(page);

    for (const mask of MASKS) {
      await gotoStable(page, mask.path);
      await assertNoHorizontalOverflow(page, mask.label);
    }
  });

  test('tasks hero action is tappable and task sub-views fit the phone', async ({ page }) => {
    await login(page);

    // Tasks list: the "New task" primary action must meet the 44px target, and
    // the table must not scroll sideways (which used to clip the status column).
    await gotoStable(page, '/admin/tasks');
    await assertTapTarget(page.locator('.ph-act a.btn-primary').first(), 'Tasks hero · New task');
    await assertNoElementXScroll(page, '.fi-ta-ctn', 'Tasks table');

    // Drill into the anchor demo task (InReview) via the searchable name column.
    const search = page.getByRole('searchbox').first();
    await search.fill('Demo · Live deployment');
    const row = page.getByRole('link', { name: 'Demo · Live deployment' });
    await expect(row.first()).toBeVisible();
    await row.first().click();
    await expect(page).toHaveURL(/\/admin\/tasks\/[^/]+$/);

    const taskPath = new URL(page.url()).pathname;
    await assertNoHorizontalOverflow(page, 'Task detail · thread');

    // Every bespoke task sub-view (its own route) must also fit at 375px.
    const subViews = ['concept', 'diff', 'logs', 'quality-gates', 'respond'];
    for (const sub of subViews) {
      await gotoStable(page, `${taskPath}/${sub}`);
      await assertNoHorizontalOverflow(page, `Task detail · ${sub}`);
    }
  });

  test('respond dock actions stay on-screen and tappable', async ({ page }) => {
    await login(page);

    // A concept_review task renders the inline respond dock with a textarea and
    // two stacked action buttons — the spot where avatar + textarea + buttons
    // used to overflow the row and clip the primary button off the right edge.
    await gotoStable(page, '/admin/tasks');
    const search = page.getByRole('searchbox').first();
    await search.fill('Review concept');
    const row = page.getByRole('link', { name: /Review concept/i }).first();
    await expect(row).toBeVisible();
    await row.click();
    await expect(page).toHaveURL(/\/admin\/tasks\/[^/]+$/);

    await assertNoHorizontalOverflow(page, 'Task detail · respond dock');

    const dockButtons = page.locator('.respond-body .btn');
    const count = await dockButtons.count();
    expect(count, 'respond dock has at least one action button').toBeGreaterThan(0);
    for (let i = 0; i < count; i++) {
      const btn = dockButtons.nth(i);
      await assertWithinViewport(page, btn, `Respond dock button ${i}`);
      await assertTapTarget(btn, `Respond dock button ${i}`);
    }
  });
});
