import { expect, type Locator, type Page } from '@playwright/test';
import type { FakeReview } from './fakeReview';

/** The host of the open shadow root that `@pierre/diffs` renders into. CSS locators pierce it. */
export const CODE_HOST = 'diffs-container';

/** The new-comment box. A reply box is labelled "Reply". */
export const COMPOSER = 'textarea[aria-label^="Comment on"]';

export const SAVED_THREAD = '[data-testid="thread"][data-request-id]';

export const FILE_THREADS = '[data-testid="file-threads"]';

const MAXIMUM_TABS = 40;

export function lineNumber(page: Page, line: number): Locator {
    return page.locator(`${CODE_HOST} [data-column-number="${line}"]`);
}

/** The decoded path and the hash of the page URL. */
export function location(page: Page): string {
    const url = new URL(page.url());

    return `${decodeURIComponent(url.pathname)}${url.hash}`;
}

export function selectedLines(page: Page): Promise<string[]> {
    return page.locator(`${CODE_HOST} [data-line][data-selected-line]`).evaluateAll((lines) => lines.map((line) => line.getAttribute('data-line') ?? ''));
}

export async function openFile(page: Page, review: FakeReview, path: string, hash = ''): Promise<void> {
    await page.goto(review.fileUrl(path, hash));
    await expect(page.locator('[data-testid="file-header"]')).toContainText(path);
}

/** The worker replaces each row when its highlighting arrives, which ends a hover on the old row. */
export async function waitForHighlighting(page: Page, line: number): Promise<void> {
    await expect(page.locator(`${CODE_HOST} [data-line="${line}"] span[style]`).first()).toBeAttached();
}

/** Hovers the line number, then presses the "+" that the hover shows and releases it over `releaseLine`. */
export async function dragFromPlus(page: Page, pressLine: number, releaseLine: number): Promise<void> {
    const number = lineNumber(page, pressLine);
    // The view ignores the pointer for 120 ms after each scroll. A hover that scrolls loses the line, and with it the "+".
    await number.evaluate(async (element) => {
        element.scrollIntoView({ block: 'center' });
        // The scroll event comes with the next frame.
        await new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())));
    });
    await expect.poll(() => receivesPointer(number)).toBe(true);
    await number.hover();
    const plus = page.locator(`${CODE_HOST} [data-utility-button]`);
    await expect(plus).toHaveCount(1);
    await plus.hover();
    await expect.poll(() => receivesPointer(plus)).toBe(true);
    await page.mouse.down();
    await lineNumber(page, releaseLine).hover();
    await page.mouse.up();
}

function receivesPointer(locator: Locator): Promise<boolean> {
    return locator.evaluate((element) => {
        const { x, y, width, height } = element.getBoundingClientRect();
        const hit = (element.getRootNode() as Document | ShadowRoot).elementFromPoint(x + width / 2, y + height / 2);

        return null !== hit && element.contains(hit);
    });
}

export async function shiftClickLines(page: Page, firstLine: number, lastLine: number): Promise<void> {
    await lineNumber(page, firstLine).click();
    await lineNumber(page, lastLine).click({ modifiers: ['Shift'] });
}

/** The comment box that opened below `line` and has the focus. */
export async function focusedCommentBoxBelow(page: Page, line: number): Promise<Locator> {
    const textarea = page.locator(`[slot="annotation-${line}"] ${COMPOSER}`);
    await expect(textarea).toBeVisible();
    await expect(textarea).toBeFocused();

    return textarea;
}

export function commentBoxHeader(page: Page, line: number): Locator {
    return page.locator(`[slot="annotation-${line}"] [data-testid="comment-box-header"]`);
}

export function commentBoxButton(page: Page, name: string): Locator {
    return page.locator('form[data-testid="comment-box"]').getByRole('button', { name, exact: true });
}

/** Types into the comment box and clicks "Add single comment", then waits for the saved thread. */
export async function addSingleComment(page: Page, textarea: Locator, body: string, container: string): Promise<void> {
    await textarea.fill(body);
    await commentBoxButton(page, 'Add single comment').click();
    await waitForSavedThread(page, container, body);
}

/** Types into the focused comment box, then tabs to "Add single comment" and presses Enter. */
export async function keyboardComment(page: Page, body: string, container: string): Promise<void> {
    await page.keyboard.type(body);
    await tabToButton(page, 'Add single comment');
    await page.keyboard.press('Enter');
    await waitForSavedThread(page, container, body);
}

export async function waitForSavedThread(page: Page, container: string, text: string): Promise<void> {
    await expect(page.locator(`${container} ${SAVED_THREAD}`).filter({ hasText: text })).toHaveCount(1);
}

export async function tabToButton(page: Page, text: string): Promise<void> {
    await tabUntil(page, () => page.evaluate((name) => 'BUTTON' === document.activeElement?.tagName && name === document.activeElement.textContent?.trim(), text), `the "${text}" button`);
}

export async function tabUntil(page: Page, focused: () => Promise<boolean>, target: string): Promise<void> {
    for (let tabs = 0; tabs < MAXIMUM_TABS; tabs++) {
        if (await focused()) {
            return;
        }
        await page.keyboard.press('Tab');
    }

    throw new Error(`Tab did not reach ${target}.`);
}

export function wrapToggle(page: Page): Locator {
    return page.locator('[data-testid="file-header"]').getByRole('button', { name: 'Wrap lines' });
}

export async function turnOnWrap(page: Page): Promise<void> {
    await wrapToggle(page).click();
    await expect(wrapToggle(page)).toHaveAttribute('aria-pressed', 'true');
}

/** The Symbols menu is disabled until V9 answers. */
export async function waitForSymbols(page: Page): Promise<void> {
    await expect(page.locator('[data-testid="file-header"]').getByRole('button', { name: 'Symbols' })).toBeEnabled();
}
