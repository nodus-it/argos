import { test, expect } from '@playwright/test';
import { MATRIX } from './matrix';
import { connectAccount, resetDatabase } from './helpers/reset';
import { login } from './helpers/auth';
import { completeOnboarding } from './helpers/onboarding';
import { createProject } from './helpers/project';
import { createTask, expectBodyText, runConcept, runImplement } from './helpers/task';

/**
 * Gemockt full-flow: login → onboarding → project → task → concept → implement,
 * parametrised over the 4-run matrix. Requires the compose stack running with
 * ARGOS_E2E_FAKE=1 (deterministic, offline — no real tokens/API/docker).
 *
 * UNRUN draft: depends on the flow helpers, which need a local pass to finalise
 * Filament Select interactions and localised action names.
 */
for (const run of MATRIX) {
  test.describe(`full flow · ${run.name}`, () => {
    test.beforeEach(() => {
      resetDatabase('DatabaseSeeder');
      if (run.authMethod === 'oauth') {
        connectAccount(run.platform, run.instanceUrl);
      }
    });

    test('completes concept and implement', async ({ page }) => {
      const projectName = `E2E ${run.platform}`;

      await login(page);
      await completeOnboarding(page, run.agent);
      await createProject(page, {
        name: projectName,
        platform: run.platform,
        authMethod: run.authMethod,
      });
      await createTask(page, {
        name: `E2E task — ${run.name}`,
        project: projectName,
        description: 'Deterministic E2E flow.',
      });

      await runConcept(page);
      await expectBodyText(page, /Concept/i);

      await runImplement(page);
      // Implement completed in fake mode → the Push & PR action is now available.
      await expect(page.getByRole('button', { name: /push/i }).first()).toBeVisible();
    });
  });
}
