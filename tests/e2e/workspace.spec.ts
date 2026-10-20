import { test, expect, type Browser, type Page } from '@playwright/test';
import { resetDatabase } from './helpers/reset';
import { login } from './helpers/auth';

/**
 * Pair programming in a workspace (fake mode): two browsers share one thread.
 * Requires the compose stack in E2E mode (ARGOS_E2E_FAKE=1) including the
 * `reverb` service — live updates travel over the workspace presence channel.
 */
async function openWorkspace(browser: Browser, email: string): Promise<Page> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await login(page, email);
  await page.goto('/admin/workspaces');
  await page.getByText('E2E pairing').first().click();
  await page.waitForURL(/\/admin\/workspaces\/[0-9A-Za-z]+/);
  await expect(page.locator('.ws-chat')).toBeVisible();
  return page;
}

async function send(page: Page, text: string): Promise<void> {
  const composer = page.locator('.ws-composer textarea');
  await composer.fill(text);
  await composer.press('Enter');
}

test.describe('workspace pair programming', () => {
  test.beforeEach(() => {
    resetDatabase('E2eWorkspaceSeeder');
  });

  test('both members follow one thread live and either can decide an approval', async ({ browser }) => {
    const ada = await openWorkspace(browser, 'admin@argos.local');
    const pair = await openWorkspace(browser, 'pair@argos.local');

    // Presence: each sees two people in the workspace.
    await expect(ada.locator('.ws-presence .ws-avatar')).toHaveCount(2, { timeout: 15_000 });
    await expect(pair.locator('.ws-presence .ws-avatar')).toHaveCount(2, { timeout: 15_000 });

    // Ada writes; the answer reaches Pair without a reload.
    await send(ada, 'Hello from Ada');
    await expect(ada.getByText('Echo: [Argos Admin]: Hello from Ada')).toBeVisible({ timeout: 15_000 });
    await expect(pair.getByText('Echo: [Argos Admin]: Hello from Ada')).toBeVisible({ timeout: 15_000 });

    // Pair asks for an edit; the approval card shows up for both.
    await send(pair, 'please edit the readme');
    await expect(ada.locator('.ws-approval.is-pending')).toBeVisible({ timeout: 15_000 });
    await expect(pair.locator('.ws-approval.is-pending')).toBeVisible({ timeout: 15_000 });

    // Pair allows it; Ada sees the result and who decided.
    await pair.locator('.ws-approval.is-pending .btn-success').click();
    await expect(ada.getByText('Done — README.md is updated.')).toBeVisible({ timeout: 15_000 });
    await expect(ada.locator('.ws-approval')).toContainText('Pair Partner');
    await expect(ada.locator('.ws-approval.is-pending')).toHaveCount(0);
  });

  test('the thread sidebar shows another thread waiting for a decision', async ({ browser }) => {
    const ada = await openWorkspace(browser, 'admin@argos.local');
    const pair = await openWorkspace(browser, 'pair@argos.local');

    // Pair switches to the side thread and asks for an edit there.
    await pair.locator('.ws-thread-link', { hasText: 'Side quest' }).click();
    await expect(pair.locator('.ws-thread-title')).toHaveText('Side quest');
    await send(pair, 'please edit the readme');

    // Ada stays in "Pairing" and sees the side thread waiting, with unread entries.
    const side = ada.locator('.ws-thread-item', { hasText: 'Side quest' });
    await expect(side.locator('.ws-status-waiting_input')).toBeVisible({ timeout: 15_000 });
    await expect(side.locator('.ws-unread')).toBeVisible({ timeout: 15_000 });
    await expect(ada.locator('.ws-thread-title')).toHaveText('Pairing');
  });
});
