import { type Page } from '@playwright/test';
import { type Agent } from '../matrix';

/**
 * Complete onboarding step 1 (agent auth), which is what unlocks the onboarding
 * gate so the rest of the panel is reachable. Uses the page's Livewire
 * wire:model / wire:click hooks as selectors (i18n-independent, robust).
 *
 * In fake mode the Anthropic validator always passes, so any token string works.
 *
 * UNRUN: needs local `npx playwright test` to confirm the post-save wait.
 */
export async function completeOnboarding(page: Page, agent: Agent): Promise<void> {
  await page.goto('/admin/onboarding');

  if (agent === 'claude-code') {
    await page.locator('input[wire\\:model="claudeToken"]').fill('e2e-fake-claude-token');
    await page.locator('[wire\\:click="saveClaudeToken"]').click();
  } else {
    await page
      .locator('textarea[wire\\:model="codexAuthJson"]')
      .fill('{"OPENAI_API_KEY":"e2e-fake-key"}');
    await page.locator('[wire\\:click="saveCodexAuthJson"]').click();
  }

  // Saving persists the AgentCredential and unlocks the gate; let the Livewire
  // round-trip settle before navigating on.
  await page.waitForLoadState('networkidle');
}
