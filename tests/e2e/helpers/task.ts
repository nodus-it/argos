import { type Page, expect } from '@playwright/test';
import { chooseSelect, fillField, gotoStable } from './filament';

/**
 * Task creation + phase triggering against ViewTask. The phase header actions
 * dispatch the phase to the queue and redirect back to the view; in fake mode
 * FakePhaseRunner completes them in seconds. Completion is observed by the NEXT
 * phase action unlocking (concept done → Implement appears → Push & PR appears),
 * which is a far more robust signal than a status string.
 */
export async function createTask(
  page: Page,
  opts: { name: string; project: string; description?: string },
): Promise<void> {
  await gotoStable(page, '/admin/tasks/create');

  await fillField(page, 'name', opts.name);
  await chooseSelect(page, 'repo_profile_id', opts.project);
  if (opts.description !== undefined) {
    await fillField(page, 'description', opts.description);
  }

  await page.getByRole('button', { name: /^create$/i }).click();
  await page.waitForURL(/\/admin\/tasks\/[^/]+/);
}

/** Click a header phase action (the primary button in the page header). */
async function clickPhaseAction(page: Page, name: RegExp): Promise<void> {
  await page.getByRole('button', { name }).first().click();
  await page.waitForLoadState('networkidle');
}

/**
 * Poll for a header action button to appear, reloading between checks. Phases run
 * async on the queue, so the view needs a refresh to reflect the new status.
 */
async function waitForActionButton(page: Page, name: RegExp, timeoutMs = 60_000): Promise<void> {
  const deadline = Date.now() + timeoutMs;
  for (;;) {
    if (await page.getByRole('button', { name }).first().isVisible().catch(() => false)) {
      return;
    }
    if (Date.now() > deadline) {
      break;
    }
    await page.waitForTimeout(1_500);
    await page.reload();
    await page.waitForLoadState('networkidle');
  }
  await expect(page.getByRole('button', { name }).first()).toBeVisible();
}

/** Trigger the concept phase and wait for it to complete (Implement unlocks). */
export async function runConcept(page: Page): Promise<void> {
  await clickPhaseAction(page, /create concept|update concept|^concept$/i);
  await waitForActionButton(page, /^implement$/i);
}

/** Trigger the implement phase and wait for it to complete (Push & PR unlocks). */
export async function runImplement(page: Page): Promise<void> {
  await clickPhaseAction(page, /^implement$/i);
  await waitForActionButton(page, /push/i);
}

/** Poll the page body for an expected status string (queue is async). */
export async function expectBodyText(page: Page, text: string | RegExp): Promise<void> {
  await expect(page.locator('body')).toContainText(text, { timeout: 60_000 });
}
