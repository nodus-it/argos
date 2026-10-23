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

// Lists, account and administration masks that must stay overflow-free on a
// phone, plus the in-app docs viewer (concept overlap D).
const MASKS: { label: string; path: string }[] = [
  { label: 'My work', path: '/' },
  { label: 'Workspaces', path: '/workspaces' },
  { label: 'Profile', path: '/account/profile' },
  { label: 'Claude subscriptions', path: '/account/agents' },
  { label: 'Connected accounts', path: '/account/connections' },
  { label: 'Administration', path: '/system/overview' },
  { label: 'Users', path: '/system/users' },
  { label: 'API clients', path: '/system/api-clients' },
  { label: 'Documentation', path: '/docs' },
  // Forms must not break on mobile (Filament stacks grids to 1 column below lg).
  { label: 'Create workspace', path: '/new' },
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

    await gotoStable(page, '/workspaces');
    await assertNoHorizontalOverflow(page, 'Workspaces list');
    await page.getByText('E2E pairing').first().click();
    await expect(page.locator('.ag-chat')).toBeVisible();

    await assertNoHorizontalOverflow(page, 'Workspace · chat');

    // KNOWN FAILURE (app bug, not a stale selector): the redesigned send button
    // `.ag-send` is a fixed 36x36px (resources/css/workspace.css) and the mobile
    // 44px touch-target rules in resources/css/app.css only cover the legacy
    // `.btn`/`.ws-*` controls. Fix the CSS, don't relax this check.
    await assertTapTarget(page.locator('.ag-composer').getByRole('button', { name: 'Send', exact: true }), 'Workspace · Send');

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

    await gotoStable(page, '/workspaces');
    await page.getByText('E2E pairing').first().click();
    await expect(page.locator('.ag-chat')).toBeVisible();

    await page.getByTestId('switcher-new-task').click();
    const form = page.getByTestId('new-task-form');
    await form.getByRole('checkbox', { name: /Run automatically/ }).check();
    // The auto options (plan, when done, fix rounds) are revealed by the toggle.
    await expect(form.getByRole('combobox', { name: 'Plan', exact: true })).toBeVisible();
    await expect(form.getByRole('combobox', { name: 'When done', exact: true })).toBeVisible();

    await assertNoHorizontalOverflow(page, 'Workspace · new auto-task form');
    await assertTapTarget(form.getByRole('button', { name: 'Start automatically' }), 'Workspace · Start automatic run');
  });
});
