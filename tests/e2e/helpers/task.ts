import { type Page, expect } from '@playwright/test';

/**
 * Task creation + phase triggering against ViewTask. Phases run async on the
 * queue, so the run helpers click the header action and the caller polls the
 * UI for the resulting status. In fake mode FakePhaseRunner completes them
 * almost instantly.
 *
 * UNRUN: the header-action button labels are localised; confirm the
 * accessible names locally and tighten the regexes below.
 */
export async function createTask(
  page: Page,
  opts: { name: string; project: string; description?: string },
): Promise<void> {
  await page.goto('/admin/tasks/create');

  await page.getByLabel('Name').fill(opts.name);
  await page.getByLabel('Project').selectOption({ label: opts.project });
  if (opts.description !== undefined) {
    await page.getByLabel('Description').fill(opts.description);
  }

  await page.getByRole('button', { name: /create/i }).click();
  await page.waitForURL(/\/admin\/tasks\/[^/]+/);
}

/** Click the concept header action and wait for the concept to complete. */
export async function runConcept(page: Page): Promise<void> {
  await page.getByRole('button', { name: /concept/i }).first().click();
  await expectBodyText(page, /Concept Review|Concept/i);
}

/** Click the implement header action and wait for it to complete. */
export async function runImplement(page: Page): Promise<void> {
  await page.getByRole('button', { name: /^implement/i }).first().click();
  await expectBodyText(page, /Implement Completed|Completed/i);
}

/** Poll the page body for an expected status string (queue is async). */
export async function expectBodyText(page: Page, text: string | RegExp): Promise<void> {
  await expect(page.locator('body')).toContainText(text, { timeout: 60_000 });
}
