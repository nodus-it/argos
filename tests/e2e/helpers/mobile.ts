import { type Locator, type Page, expect } from '@playwright/test';

/**
 * Mobile-gate assertions for the P9 verification suite (concepts/mobile.md).
 * Kept tiny and dependency-free so the gate stays readable.
 */

/**
 * The core "fertig" criterion: the page must not scroll horizontally. We measure
 * the document element rather than `body`, because horizontal page scroll is
 * governed by the root scroller — and an inner element that scrolls on its own
 * (e.g. the diff body or terminal, `overflow-x:auto`) deliberately does NOT
 * inflate it, so allowed internal scroll passes while real page overflow fails.
 * A 1px tolerance absorbs sub-pixel rounding.
 */
export async function assertNoHorizontalOverflow(page: Page, label: string): Promise<void> {
  const { scrollWidth, clientWidth } = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));

  expect(
    scrollWidth,
    `${label}: horizontal overflow (scrollWidth ${scrollWidth} > clientWidth ${clientWidth})`,
  ).toBeLessThanOrEqual(clientWidth + 1);
}

/**
 * Assert a specific scroll container does not overflow horizontally. The page
 * may be overflow-free while a Filament table scrolls sideways inside its own
 * `.fi-ta-ctn` (so columns like the task status get clipped off the right) —
 * this catches that without coupling to any column text.
 */
export async function assertNoElementXScroll(page: Page, selector: string, label: string): Promise<void> {
  const el = page.locator(selector).first();
  await expect(el, `${label}: ${selector} not found`).toBeVisible();
  const overflow = await el.evaluate((node) => ({
    scrollWidth: node.scrollWidth,
    clientWidth: node.clientWidth,
  }));
  expect(
    overflow.scrollWidth,
    `${label}: ${selector} scrolls horizontally (scrollWidth ${overflow.scrollWidth} > clientWidth ${overflow.clientWidth})`,
  ).toBeLessThanOrEqual(overflow.clientWidth + 1);
}

/**
 * Assert a control is visible and meets the 44px minimum touch-target height
 * (Apple HIG / the concept's gate). Used on the key actions (new task, respond,
 * approve) rather than every button.
 */
export async function assertTapTarget(locator: Locator, label: string): Promise<void> {
  await expect(locator, `${label}: not visible`).toBeVisible();

  const box = await locator.boundingBox();
  expect(box, `${label}: no bounding box`).not.toBeNull();
  expect(
    Math.round(box!.height),
    `${label}: touch target ${Math.round(box!.height)}px < 44px`,
  ).toBeGreaterThanOrEqual(44);
}

/**
 * Assert a control sits fully inside the viewport horizontally — catches the
 * class of bug where a button is pushed past the right edge and clipped by a
 * parent `overflow:hidden` (so it never triggers a page scroll, yet is half
 * off-screen and barely tappable). 1px tolerance for sub-pixel rounding.
 */
export async function assertWithinViewport(page: Page, locator: Locator, label: string): Promise<void> {
  await expect(locator, `${label}: not visible`).toBeVisible();

  const viewport = page.viewportSize();
  const box = await locator.boundingBox();
  expect(box, `${label}: no bounding box`).not.toBeNull();
  expect(box!.x, `${label}: starts off-screen left (x ${Math.round(box!.x)})`).toBeGreaterThanOrEqual(-1);
  expect(
    Math.round(box!.x + box!.width),
    `${label}: right edge ${Math.round(box!.x + box!.width)} exceeds viewport ${viewport!.width}`,
  ).toBeLessThanOrEqual(viewport!.width + 1);
}
