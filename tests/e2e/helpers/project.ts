import { type Page, expect } from '@playwright/test';
import { type AuthMethod, type Platform } from '../matrix';
import { chooseSelect, fillField, gotoStable } from './filament';

const PLATFORM_LABEL: Record<Platform, string> = {
  github: 'GitHub',
  gitlab: 'GitLab',
  bitbucket: 'Bitbucket',
};

/**
 * Create a RepoProfile via the resource form. The form is a `->live()` wizard:
 * choosing the platform unlocks the auth method, which in turn reveals either the
 * manual (PAT) fields or the OAuth account/repo/branch selects. In fake mode the
 * FakeGitService backs the repo/branch dropdowns with canonical data
 * (argos-e2e/demo-app, branch main).
 */
export async function createProject(
  page: Page,
  opts: { name: string; platform: Platform; authMethod: AuthMethod },
): Promise<void> {
  await gotoStable(page, '/admin/repo-profiles/create');

  await chooseSelect(page, 'platform', PLATFORM_LABEL[opts.platform]);

  // auth_method renders only for platforms that offer OAuth; PAT may be implicit.
  const authWrapper = page.locator('div.fi-fo-field', {
    has: page.locator('label[for="form.auth_method"]'),
  });
  const hasAuthMethod = await authWrapper
    .waitFor({ state: 'visible', timeout: 3000 })
    .then(() => true)
    .catch(() => false);
  if (hasAuthMethod) {
    await chooseSelect(
      page,
      'auth_method',
      opts.authMethod === 'pat' ? /Personal Access Token/ : /OAuth/,
    );
  }

  if (opts.authMethod === 'pat') {
    await fillField(page, 'url', 'https://example.test/argos-e2e/demo-app');
    await fillField(page, 'token', 'e2e-fake-pat');
    // url is ->live(onBlur), so move focus off it to trigger the branch fetch
    // that unlocks the default-branch select.
    await page.keyboard.press('Tab');
    await chooseSelect(page, 'default_branch', 'main');
  } else {
    // A ConnectedAccount was seeded via helpers/reset connectAccount(); pick it.
    await chooseSelect(page, 'connected_account_id', /\S/);
    await chooseSelect(page, 'oauth_repo', 'argos-e2e/demo-app');
    await chooseSelect(page, 'oauth_branch', 'main');
  }

  await fillField(page, 'name', opts.name);
  await page.getByRole('button', { name: /^create$/i }).click();
  await expect(page).toHaveURL(/\/admin\/repo-profiles(\/|$)/);
}
