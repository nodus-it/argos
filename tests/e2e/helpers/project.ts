import { type Page } from '@playwright/test';
import { type AuthMethod, type Platform } from '../matrix';

/**
 * Create a RepoProfile via the resource form. In fake mode the FakeGitService
 * fills the repo/branch dropdowns with canonical data, so PAT runs just type a
 * URL/token and OAuth runs pick the seeded ConnectedAccount + a fake repo.
 *
 * UNRUN: Filament Select components may not be native <select>; selectOption
 * calls likely need local adjustment to the rendered control.
 */
export async function createProject(
  page: Page,
  opts: { name: string; platform: Platform; authMethod: AuthMethod },
): Promise<void> {
  await page.goto('/admin/repo-profiles/create');

  await page.getByLabel('Platform').selectOption(opts.platform);
  await page.getByLabel('Authentication method').selectOption(opts.authMethod);

  if (opts.authMethod === 'pat') {
    await page.getByLabel('Repo URL').fill('https://example.test/argos-e2e/demo-app');
    await page.getByLabel('Token (PAT)').fill('e2e-fake-pat');
    await page.getByLabel('Default Branch').selectOption('main');
  } else {
    // A ConnectedAccount was seeded via helpers/reset connectAccount().
    await page.getByLabel(/Account/).selectOption({ index: 1 });
    await page.getByLabel(/Repo URL|Repository/).selectOption('argos-e2e/demo-app');
    await page.getByLabel('Default Branch').selectOption('main');
  }

  await page.getByLabel('Project Name').fill(opts.name);
  await page.getByRole('button', { name: /create/i }).click();
  await page.waitForLoadState('networkidle');
}
