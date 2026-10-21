import { test, expect } from '@playwright/test';
import { login } from './helpers/auth';
import { resetDatabase } from './helpers/reset';
import { gotoStable } from './helpers/filament';
import {
  assertNoHorizontalOverflow,
  assertTapTarget,
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
 * Seeded with FullDemoSeeder so the lists and masks have content to render;
 * the workspace checks use E2eWorkspaceSeeder. Offline/fake
 * stack — no tokens or worker needed, same as the rest of the gemockt suite.
 */

// List + admin masks that must stay overflow-free on a phone. Mirrors the
// desktop settings-walk plus the in-app docs viewer (concept overlap D).
const MASKS: { label: string; path: string }[] = [
  { label: 'Dashboard', path: '/admin' },
  { label: 'Workspaces (admin)', path: '/admin/workspaces' },
  { label: 'API clients', path: '/admin/api-clients' },
  { label: 'Agent credentials', path: '/admin/agent-credentials' },
  { label: 'Connected accounts', path: '/admin/connected-accounts' },
  { label: 'Settings', path: '/admin/settings' },
  { label: 'Documentation', path: '/admin/docs' },
  { label: 'Workspaces', path: '/w/workspaces' },
  { label: 'My work', path: '/w/my-work' },
  { label: 'Users', path: '/admin/users' },
  // Forms must not break on mobile (Filament stacks grids to 1 column below lg).
  { label: 'Create workspace', path: '/w/workspaces/create' },
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

  test('workspace page fits the phone and its composer actions are tappable', async ({ page }) => {
    resetDatabase('E2eWorkspaceSeeder');
    await login(page);

    await gotoStable(page, '/w/workspaces');
    await assertNoHorizontalOverflow(page, 'Workspaces list');
    await page.getByText('E2E pairing').first().click();
    await expect(page.locator('.ws-chat')).toBeVisible();

    await assertNoHorizontalOverflow(page, 'Workspace · chat');

    await assertTapTarget(page.locator('.ws-composer-bar .btn-primary').first(), 'Workspace · Send');

    // The workspace settings (every tab) must fit the phone as well.
    const settings = page.url().replace(/\?.*$/, '') + '/settings';
    for (const tab of ['general', 'environment', 'resources', 'integrations', 'automation', 'members']) {
      await gotoStable(page, `${settings}?tab=${tab}`);
      await assertNoHorizontalOverflow(page, `Workspace settings · ${tab}`);
    }
  });

  test('the new-task form with the auto toggle fits the phone', async ({ page }) => {
    resetDatabase('E2eWorkspaceSeeder');
    await login(page);

    await gotoStable(page, '/w/workspaces');
    await page.getByText('E2E pairing').first().click();
    await expect(page.locator('.ws-chat')).toBeVisible();

    await page.locator('[wire\\:click="startCreating"]').click();
    await page.locator('.ws-auto-toggle input[type="checkbox"]').check();
    await expect(page.locator('.ws-auto-grid')).toBeVisible();

    await assertNoHorizontalOverflow(page, 'Workspace · new auto-task form');
    await assertTapTarget(page.locator('.ws-new-task button[type="submit"]'), 'Workspace · Start automatic run');
  });
});
