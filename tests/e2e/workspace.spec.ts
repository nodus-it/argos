import { test, expect, type Browser, type Page } from '@playwright/test';
import { resetDatabase } from './helpers/reset';
import { login } from './helpers/auth';

/**
 * Pair programming in a workspace (fake mode): two browsers share one task.
 * Requires the compose stack in E2E mode (ARGOS_E2E_FAKE=1) including the
 * `reverb` service — live updates travel over the workspace presence channel.
 */
async function openWorkspace(browser: Browser, email: string): Promise<Page> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await login(page, email);
  await page.goto('/workspaces');
  await page.getByText('E2E pairing').first().click();
  await page.waitForURL(/:\d+\/(?!workspaces\b|new\b)[a-z0-9][a-z0-9-]*(\/|$)/);
  await expect(page.locator('.ag-chat')).toBeVisible();
  return page;
}

async function send(page: Page, text: string): Promise<void> {
  const composer = page.locator('.ag-composer textarea');
  await composer.fill(text);
  await composer.press('Enter');
}

test.describe('workspace pair programming', () => {
  test.beforeEach(() => {
    resetDatabase('E2eWorkspaceSeeder');
  });

  test('both members follow one task live and either can decide an approval', async ({ browser }) => {
    const ada = await openWorkspace(browser, 'admin@argos.local');
    const pair = await openWorkspace(browser, 'pair@argos.local');

    // Presence: each sees two people in the workspace.
    await expect(ada.getByTestId('presence').getByTestId('presence-avatar')).toHaveCount(2, { timeout: 15_000 });
    await expect(pair.getByTestId('presence').getByTestId('presence-avatar')).toHaveCount(2, { timeout: 15_000 });

    // Ada writes; the answer reaches Pair without a reload.
    await send(ada, 'Hello from Ada');
    await expect(ada.getByText('Echo: [Argos Admin]: Hello from Ada')).toBeVisible({ timeout: 15_000 });
    await expect(pair.getByText('Echo: [Argos Admin]: Hello from Ada')).toBeVisible({ timeout: 15_000 });

    // Pair asks for an edit; the approval card shows up for both.
    await send(pair, 'please edit the readme');
    await expect(ada.locator('.ag-approval.is-pending')).toBeVisible({ timeout: 15_000 });
    await expect(pair.locator('.ag-approval.is-pending')).toBeVisible({ timeout: 15_000 });

    // Pair allows it; Ada sees the result and the decided card is gone for her too.
    // Note: an allowed tool approval no longer keeps a card ("resolved by …") in the
    // chat — it folds into the step row (TaskTimeline::silent), so who decided is
    // no longer shown in the UI and can't be asserted here.
    await pair.locator('.ag-approval.is-pending').getByRole('button', { name: 'Allow', exact: true }).click();
    await expect(ada.getByText('Done — README.md is updated.')).toBeVisible({ timeout: 15_000 });
    await expect(ada.locator('.ag-step', { hasText: 'README.md' })).toBeVisible();
    await expect(ada.locator('.ag-approval.is-pending')).toHaveCount(0);
    await expect(pair.locator('.ag-approval.is-pending')).toHaveCount(0);
  });

  test('the task switcher shows another task waiting for a decision', async ({ browser }) => {
    const ada = await openWorkspace(browser, 'admin@argos.local');
    const pair = await openWorkspace(browser, 'pair@argos.local');

    // Pair switches to the side task (header task switcher) and asks for an edit there.
    await pair.getByTestId('task-switcher').click();
    await pair.getByRole('dialog', { name: 'Tasks' }).getByRole('option', { name: /Side quest/ }).click();
    await expect(pair.getByTestId('task-switcher').locator('.ag-switch-title')).toHaveText('Side quest');
    await send(pair, 'please edit the readme');

    // Ada stays in "Pairing" and sees the side task waiting, with unread entries.
    await expect(ada.getByRole('button', { name: /waits for approval/ })).toBeVisible({ timeout: 15_000 });
    await ada.getByTestId('task-switcher').click();
    const side = ada.getByRole('dialog', { name: 'Tasks' }).getByRole('option', { name: /Side quest/ });
    await expect(side).toHaveAttribute('data-group', 'waiting', { timeout: 15_000 });
    await expect(side.locator('.ag-unread')).toBeVisible({ timeout: 15_000 });
    await expect(ada.getByTestId('task-switcher').locator('.ag-switch-title')).toHaveText('Pairing');
  });

  test('the composer suggests commands and files', async ({ browser }) => {
    const ada = await openWorkspace(browser, 'admin@argos.local');
    const composer = ada.locator('.ag-composer textarea');
    const suggestions = ada.getByTestId('composer-suggestions').getByRole('option');

    await composer.pressSequentially('/pre');
    await expect(suggestions).toHaveCount(1);
    await composer.press('Enter');
    await expect(composer).toHaveValue('/preview ');

    await composer.fill('');
    await composer.pressSequentially('look at @login');
    await expect(suggestions.first()).toContainText('LoginController.php', { timeout: 10_000 });
    await composer.press('Enter');
    await expect(composer).toHaveValue('look at @app/Http/Controllers/LoginController.php ');
  });

  test('quality gates run from the composer and failures go to the agent', async ({ browser }) => {
    const ada = await openWorkspace(browser, 'admin@argos.local');

    // `/gates` runs the gates; the results land in the context sidebar.
    await send(ada, '/gates');
    const gates = ada.locator('#ctx-gates');
    await expect(gates).toBeVisible();
    await expect(gates.locator('.ag-gate-item')).toHaveCount(4);
    await expect(gates.locator('.ag-gate-state.is-running')).toHaveCount(0, { timeout: 15_000 });
    await expect(gates.locator('.ag-gate-state.is-open')).toHaveCount(0);
    const pest = gates.getByRole('button', { name: /Pest/ });
    await expect(pest).toContainText('failed');

    // The gate detail shows the failing test and hands the failure to the agent.
    await pest.click();
    const detail = ada.getByRole('dialog', { name: /Pest/ });
    await expect(detail).toContainText('LoginTest', { timeout: 15_000 });
    await detail.getByRole('button', { name: /Let Claude fix it/ }).click();
    await expect(ada.locator('.ag-msg').last()).toContainText('Quality-Gate-Fix: pest', { timeout: 15_000 });
  });

  test('a turn can be reset and the reset undone', async ({ browser }) => {
    const ada = await openWorkspace(browser, 'admin@argos.local');

    await send(ada, 'Hello checkpoint');
    await expect(ada.getByText('Echo: [Argos Admin]: Hello checkpoint')).toBeVisible({ timeout: 15_000 });

    await ada.locator('.ag-msg', { hasText: 'Hello checkpoint' }).getByRole('button', { name: 'Reset to here' }).click();
    const dialog = ada.getByRole('dialog');
    await expect(dialog).toContainText('Hello Argos');
    await dialog.getByRole('button', { name: 'Reset', exact: true }).click();
    await expect(dialog).toHaveCount(0);

    const notice = ada.locator('.ag-notice', { hasText: 'Hello checkpoint' });
    await expect(notice).toBeVisible();
    await notice.getByRole('button').click();
    await expect(ada.getByRole('dialog')).toBeVisible();
  });
});
