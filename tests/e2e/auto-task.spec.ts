import { test, expect, type Page } from '@playwright/test';
import { resetDatabase } from './helpers/reset';
import { login } from './helpers/auth';

/**
 * Auto-task in a workspace (fake mode): an automatic run plans, waits for
 * plan approval, implements, passes the quality gates and finishes on its own.
 * Requires the compose stack in E2E mode (ARGOS_E2E_FAKE=1): the
 * FakeWorkspaceDaemon emits the plan approval in plan mode and passes the
 * gates by default; the queue worker advances the run (AdvanceAutoTaskJob).
 */
async function openWorkspace(page: Page): Promise<void> {
  await login(page, 'admin@argos.local');
  await page.goto('/workspaces');
  await page.getByText('E2E pairing').first().click();
  await page.waitForURL(/:\d+\/(?!workspaces\b|new\b)[a-z0-9][a-z0-9-]*(\/|$)/);
  await expect(page.locator('.ws-chat')).toBeVisible();
}

test.describe('auto-task', () => {
  test.beforeEach(() => {
    resetDatabase('E2eWorkspaceSeeder');
  });

  test('an automatic run waits for plan approval and then finishes on its own', async ({ page }) => {
    await openWorkspace(page);

    // New task with the auto toggle on: plan = approve, ship = none (push only).
    await page.locator('[wire\\:click="startCreating"]').click();
    const form = page.locator('.ws-new-task');
    await form.locator('.ws-auto-toggle input[type="checkbox"]').check();
    await form.locator('textarea[wire\\:model="newPrompt"]').fill('Add a priority to notes');
    await form.locator('select[wire\\:model="newPlan"]').selectOption('approve');
    await form.locator('select[wire\\:model="newShip"]').selectOption('none');
    await form.locator('button[type="submit"]').click();

    const chip = page.locator('.ws-auto-chip');
    await expect(chip).toBeVisible({ timeout: 15_000 });

    // The plan approval card shows up; approve it.
    const approval = page.locator('.ws-approval.is-pending');
    await expect(approval).toBeVisible({ timeout: 30_000 });
    await expect(chip).toContainText('Plan waits for approval');
    await approval.locator('.btn-success').click();

    // Implement → gates (pass) → ship (push only) → done, without any input.
    await expect(chip).toContainText('Done', { timeout: 60_000 });
    await expect(page.locator('.ws-approval.is-pending')).toHaveCount(0);
  });

  test('a message from a human pauses the run, resume continues it', async ({ page }) => {
    await openWorkspace(page);

    await page.locator('[wire\\:click="startCreating"]').click();
    const form = page.locator('.ws-new-task');
    await form.locator('.ws-auto-toggle input[type="checkbox"]').check();
    await form.locator('textarea[wire\\:model="newPrompt"]').fill('Add a priority to notes');
    await form.locator('select[wire\\:model="newPlan"]').selectOption('approve');
    await form.locator('button[type="submit"]').click();

    const chip = page.locator('.ws-auto-chip');
    await expect(page.locator('.ws-approval.is-pending')).toBeVisible({ timeout: 30_000 });

    // Pausing from the head chip stops the automation.
    await page.locator('[wire\\:click="pauseAuto"]').click();
    await expect(chip).toContainText(/paused/i, { timeout: 15_000 });

    await page.locator('[wire\\:click="resumeAuto"]').click();
    await expect(page.locator('[wire\\:click="pauseAuto"]')).toBeVisible({ timeout: 15_000 });
  });
});
