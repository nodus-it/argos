import { type Page, expect } from '@playwright/test';

/**
 * How long to wait for a conditionally-revealed field to appear. Filament
 * `->live()` fields are unlocked by a Livewire round-trip (e.g. blurring the
 * repo URL fires the fake branch fetch that reveals `default_branch`); under CI
 * load that round-trip routinely exceeds Playwright's 5s default, which was the
 * sole cause of the full-flow suite's "element(s) not found" flakes. The happy
 * path (field already visible) returns immediately, so a generous ceiling only
 * costs wall-clock on a genuine failure.
 */
const REVEAL_TIMEOUT = 15_000;

/**
 * Navigate robustly. Filament uses Livewire SPA navigation (wire:navigate), so a
 * page.goto issued while a previous navigation is still in flight aborts with
 * net::ERR_ABORTED. Retry a couple of times, then settle on networkidle.
 */
export async function gotoStable(page: Page, url: string): Promise<void> {
  for (let attempt = 0; ; attempt++) {
    try {
      await page.goto(url, { waitUntil: 'domcontentloaded' });
      await page.waitForLoadState('networkidle');
      return;
    } catch (error) {
      if (attempt < 2 && String(error).includes('ERR_ABORTED')) {
        await page.waitForTimeout(500);
        continue;
      }
      throw error;
    }
  }
}

/**
 * Drive a Filament v5 non-native (`->native(false)`) Select. These do NOT render
 * an HTML <select>, so Playwright's selectOption() cannot be used. The control is
 * a button (`.fi-select-input-btn`) inside the field wrapper; clicking it opens a
 * teleported listbox whose entries are `<li role="option">`.
 *
 * Fields are addressed by their Filament id (`form.<statePath>`); the wrapper is
 * matched via its `<label for="form.<field>">`. Conditionally-revealed fields are
 * waited for, so callers can drive `->live()` forms step by step.
 */
export async function chooseSelect(
  page: Page,
  field: string,
  optionName: string | RegExp,
): Promise<void> {
  const wrapper = page.locator('div.fi-fo-field', {
    has: page.locator(`label[for="form.${field}"]`),
  });
  await expect(wrapper).toBeVisible({ timeout: REVEAL_TIMEOUT });

  // Filament Selects come in two flavours: native (a real <select>, the v5
  // default) and `->native(false)` (a button that opens a teleported listbox).
  const nativeSelect = wrapper.locator('select');
  if ((await nativeSelect.count()) > 0) {
    if (typeof optionName === 'string') {
      await nativeSelect.first().selectOption({ label: optionName });
    } else {
      const label = await wrapper
        .locator('option')
        .filter({ hasText: optionName })
        .first()
        .innerText();
      await nativeSelect.first().selectOption({ label });
    }
    return;
  }

  await wrapper.locator('.fi-select-input-btn').click();

  // Searchable selects reveal a filter input inside the open panel; type into it
  // so large option lists narrow down before we click.
  const search = page.locator('[role="listbox"] input[type="search"], [role="listbox"] input:not([type])');
  if (typeof optionName === 'string' && (await search.count()) > 0) {
    await search.first().fill(optionName);
  }

  await page.getByRole('option', { name: optionName }).first().click();
}

/**
 * Fill a Filament TextInput / Textarea addressed by its Filament id
 * (`form.<statePath>`). Waits for conditional reveal.
 */
export async function fillField(page: Page, field: string, value: string): Promise<void> {
  const input = page.locator(`#form\\.${field.replace(/\./g, '\\.')}`);
  await expect(input).toBeVisible({ timeout: REVEAL_TIMEOUT });
  await input.fill(value);
}
