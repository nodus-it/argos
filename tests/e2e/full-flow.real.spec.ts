import { test } from '@playwright/test';
import { login } from './helpers/auth';
import { completeOnboarding } from './helpers/onboarding';
import { createProject } from './helpers/project';
import { createTask, expectBodyText, runConcept, runImplement } from './helpers/task';

/**
 * Real full-flow: same path as the fake spec, but against a stack running
 * WITHOUT ARGOS_E2E_FAKE — real agent credentials, real git provider, a real
 * worker run, against a real throwaway test repo. Opt-in, never in CI:
 * costs money and is non-deterministic.
 *
 * Enable with ARGOS_E2E_REAL=1 and provide secrets/setup per
 * docs/PROVIDER-TEST-SETUP.md. Long timeouts — a real worker run takes minutes.
 *
 * UNRUN draft: the real onboarding/credentials path needs the secret wiring +
 * a local pass to finalise. Skipped unless explicitly enabled.
 */
test.describe('full flow (real)', () => {
  test.skip(process.env.ARGOS_E2E_REAL !== '1', 'set ARGOS_E2E_REAL=1 to run the real flow');

  test.setTimeout(20 * 60 * 1000);

  test('completes a real concept + implement run', async ({ page }) => {
    const projectName = 'E2E real';

    await login(
      page,
      process.env.ARGOS_E2E_EMAIL ?? 'admin@argos.local',
      process.env.ARGOS_E2E_PASSWORD ?? '12345',
    );

    // Real agent token comes from the environment.
    await completeOnboarding(page, 'claude-code');

    await createProject(page, { name: projectName, platform: 'github', authMethod: 'pat' });
    await createTask(page, {
      name: 'E2E real task',
      project: projectName,
      description: 'Real end-to-end smoke against the test repo.',
    });

    await runConcept(page);
    await expectBodyText(page, /Concept/i);

    await runImplement(page);
    await expectBodyText(page, /Implement Completed|Completed/i);
  });
});
