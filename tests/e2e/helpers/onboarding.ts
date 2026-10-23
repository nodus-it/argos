import { type Page } from '@playwright/test';

/**
 * Complete onboarding's agent-auth step, which is what unlocks the onboarding
 * gate so the rest of the panel is reachable. Uses the page's Livewire
 * wire:model / wire:click hooks as selectors (i18n-independent, robust).
 *
 * Step 1 is the optional security (password) step — we skip it via the
 * "Continue" button to reach the agents step where the token inputs live.
 *
 * In fake mode the Anthropic validator always passes, so any token string works.
 *
 * UNRUN: needs local `npx playwright test` to confirm the post-save wait.
 */
export async function completeOnboarding(page: Page): Promise<void> {
  await page.goto('/admin/onboarding');

  // Skip the optional security step to reach the agents step.
  await page.locator('[wire\\:click="nextStep"]').first().click();
  await page.waitForLoadState('networkidle');

  await page.locator('input[wire\\:model="claudeToken"]').fill('e2e-fake-claude-token');
  await page.locator('[wire\\:click="saveClaudeToken"]').click();

  // Saving persists the AgentCredential and unlocks the gate; let the Livewire
  // round-trip settle before navigating on.
  await page.waitForLoadState('networkidle');
}
