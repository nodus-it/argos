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
  await expect(page.locator('.ag-chat')).toBeVisible();
}

test.describe('auto-task', () => {
  test.beforeEach(() => {
    resetDatabase('E2eWorkspaceSeeder');
  });

  test('an automatic run waits for plan approval and then finishes on its own', async ({ page }) => {
    await openWorkspace(page);

    // New task (header "+" next to the task switcher) with the auto toggle on:
    // plan = approve, ship = none (push only).
    await page.getByTestId('switcher-new-task').click();
    const form = page.getByTestId('new-task-form');
    await form.getByRole('checkbox', { name: /Run automatically/ }).check();
    await form.getByRole('textbox', { name: 'Task', exact: true }).fill('Add a priority to notes');
    await form.getByRole('combobox', { name: 'Plan', exact: true }).selectOption('approve');
    await form.getByRole('combobox', { name: 'When done', exact: true }).selectOption('none');
    await form.getByRole('button', { name: 'Start automatically' }).click();

    const chip = page.getByTestId('auto-chip');
    await expect(chip).toBeVisible({ timeout: 15_000 });

    // The plan approval card shows up; approve it.
    const approval = page.locator('.ag-approval.is-pending');
    await expect(approval).toBeVisible({ timeout: 30_000 });
    await expect(chip).toContainText('Plan waits for approval');
    await approval.getByRole('button', { name: 'Accept plan' }).click();

    // Implement → gates (pass) → ship (push only) → done, without any input.
    await expect(chip).toContainText('Done', { timeout: 60_000 });
    await expect(page.locator('.ag-approval.is-pending')).toHaveCount(0);
  });

  test('a message from a human pauses the run, resume continues it', async ({ page }) => {
    await openWorkspace(page);

    await page.getByTestId('switcher-new-task').click();
    const form = page.getByTestId('new-task-form');
    await form.getByRole('checkbox', { name: /Run automatically/ }).check();
    await form.getByRole('textbox', { name: 'Task', exact: true }).fill('Add a priority to notes');
    await form.getByRole('combobox', { name: 'Plan', exact: true }).selectOption('approve');
    await form.getByRole('button', { name: 'Start automatically' }).click();

    const chip = page.getByTestId('auto-chip');
    await expect(page.locator('.ag-approval.is-pending')).toBeVisible({ timeout: 30_000 });

    // Pausing from the head chip stops the automation.
    await page.getByRole('button', { name: 'Pause automation' }).click();
    await expect(chip).toContainText(/paused/i, { timeout: 15_000 });

    await page.getByRole('button', { name: 'Continue automatically' }).click();
    await expect(page.getByRole('button', { name: 'Pause automation' })).toBeVisible({ timeout: 15_000 });
  });
});
