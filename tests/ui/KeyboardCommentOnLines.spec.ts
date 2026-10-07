import type { Locator, Page } from '@playwright/test';
import { CODE_HOST, COMPOSER, FILE_THREADS, focusedCommentBoxBelow, keyboardComment, openFile, tabToButton, tabUntil, waitForHighlighting } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { LARGE_FILE, SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

const REQUESTS = '/api/v1/reviews/:reviewId/requests';
const PLUS = `${CODE_HOST} [data-utility-button]`;

async function commentOnLines(page: Page, startLine: number, endLine: number): Promise<void> {
    await tabToButton(page, 'Comment on lines');
    await page.keyboard.press('Enter');
    await expect.poll(() => page.evaluate(() => (document.activeElement as HTMLInputElement | null)?.name)).toBe('start');
    await page.keyboard.type(String(startLine));
    await page.keyboard.press('Tab');
    await page.keyboard.type(String(endLine));
    await page.keyboard.press('Enter');
}

/**
 * Opens the file at the selection of `hash`, tabs to the "+" on its last line, and presses `key`.
 *
 * @returns The accessible name of the "+"
 */
async function pressGutterPlus(page: Page, review: FakeReview, hash: string, lastLine: number, key: string): Promise<string | null> {
    await openFile(page, review, SMALL_FILE, hash);
    await expect(page.locator(`${CODE_HOST} [data-column-number="${lastLine}"][data-selected-line] [data-utility-button]`)).toBeAttached();
    await tabUntil(page, () => page.evaluate(() => document.activeElement?.shadowRoot?.activeElement?.hasAttribute('data-utility-button') ?? false), 'the gutter "+"');
    const name = await page.locator(PLUS).getAttribute('aria-label');
    await page.keyboard.press(key);

    return name;
}

/**
 * Opens the large file with line 100 selected.
 *
 * @returns The "+" of line 100
 */
async function openWithPlusOnLine100(page: Page, review: FakeReview): Promise<Locator> {
    await openFile(page, review, LARGE_FILE, '#L100');
    const plus = page.locator(`${CODE_HOST} [data-column-number="100"] [data-utility-button]`);
    await expect(plus).toBeAttached();

    return plus;
}

/** Under the sticky file header or below the window. */
function outsideVisibleCode(locator: Locator): Promise<boolean> {
    return locator.evaluate((element) => {
        const { top, bottom } = element.getBoundingClientRect();
        const header = document.querySelector('[data-testid="file-toolbar"]')?.getBoundingClientRect();

        return undefined !== header && (bottom <= header.bottom || top >= window.innerHeight);
    });
}

/** The view renders in the animation frame after a scroll. */
function afterNextRender(page: Page): Promise<void> {
    return page.evaluate(() => new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))));
}

/** Tabs from "Download raw file", the last control before the code, to `plus`, and waits for the render of the scroll that the focus causes. */
async function tabFromHeaderTo(page: Page, plus: Locator): Promise<void> {
    await tabToButton(page, 'Copy path');
    await tabUntil(page, () => page.evaluate(() => document.activeElement?.hasAttribute('download') ?? false), '"Download raw file"');
    await page.keyboard.press('Tab');
    await afterNextRender(page);
    await expect(plus).toBeFocused();
}

/** Decision D31. Every step uses the keyboard, none uses the mouse. */
test.describe('KeyboardCommentOnLines', () => {
    test('Comment on lines 3 to 4 creates a range request with the keyboard only', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);

        await commentOnLines(page, 3, 4);
        await focusedCommentBoxBelow(page, 4);
        await keyboardComment(page, 'Merge these lines.', '[slot="annotation-4"]');

        expect(api.bodies('POST', REQUESTS)).toEqual([{ anchor: { kind: 'range', path: SMALL_FILE, range: { startLine: 3, endLine: 4 } }, body: 'Merge these lines.', requireApproval: true }]);
    });

    test('The comment box has focus when it opens', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, LARGE_FILE);

        // Far below the first rows, so the box renders only after the view scrolls there.
        await commentOnLines(page, 3000, 3002);

        await expect(await focusedCommentBoxBelow(page, 3002)).toHaveAttribute('aria-label', 'Comment on lines 3000 to 3002');
    });

    test('The file comment button works with the keyboard', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);

        await tabToButton(page, 'Comment on file');
        await page.keyboard.press('Enter');
        await expect(page.locator(`${FILE_THREADS} ${COMPOSER}`)).toBeFocused();
        await keyboardComment(page, 'Add a class docblock.', FILE_THREADS);

        expect(api.bodies('POST', REQUESTS)).toEqual([{ anchor: { kind: 'file', path: SMALL_FILE }, body: 'Add a class docblock.', requireApproval: true }]);
    });

    /** The library adds the "+" only under the pointer or on the last selected line, so the keyboard reaches it only on a selection. */
    test('The gutter + of a selection has a name and opens the comment box with Enter and with Space', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await waitForHighlighting(page, 3);
        await expect(page.locator(PLUS), 'Without a pointer and a selection, no "+" exists.').toHaveCount(0);

        const rangeName = await pressGutterPlus(page, review, '#L3-L4', 4, 'Enter');
        await focusedCommentBoxBelow(page, 4);
        await keyboardComment(page, 'Join these lines.', '[slot="annotation-4"]');
        const lineName = await pressGutterPlus(page, review, '#L6', 6, 'Space');
        await focusedCommentBoxBelow(page, 6);
        await keyboardComment(page, 'Remove this blank line.', '[slot="annotation-6"]');

        expect([rangeName, lineName]).toEqual(['Comment on lines 3 to 4', 'Comment on line 6']);
        expect(api.bodies('POST', REQUESTS)).toEqual([
            { anchor: { kind: 'range', path: SMALL_FILE, range: { startLine: 3, endLine: 4 } }, body: 'Join these lines.', requireApproval: true },
            { anchor: { kind: 'line', path: SMALL_FILE, range: { startLine: 6, endLine: 6 } }, body: 'Remove this blank line.', requireApproval: true },
        ]);
    });

    test('The gutter + outside the visible code keeps the focus that Tab gives it, and Space opens the comment box', async ({ page, api }) => {
        const plus = await openWithPlusOnLine100(page, new FakeReview(api));
        await page.evaluate(() => window.scrollBy(0, window.innerHeight / 2 + 60));
        await expect.poll(() => outsideVisibleCode(plus), 'The row of line 100 stays rendered above the visible code.').toBe(true);

        await tabFromHeaderTo(page, plus);
        await page.keyboard.press('Space');

        await expect(await focusedCommentBoxBelow(page, 100)).toHaveAttribute('aria-label', 'Comment on line 100');
    });

    test('The gutter + does not take back the focus that Tab moves out of the page', async ({ page, api }) => {
        const plus = await openWithPlusOnLine100(page, new FakeReview(api));
        await tabFromHeaderTo(page, plus);
        await page.keyboard.press('Tab');
        await expect.poll(() => page.evaluate(() => document.body === document.activeElement), 'The "+" is the last control of the page.').toBe(true);

        // The render inserts the "+" into its row again.
        await page.evaluate(() => window.scrollBy(0, 60));
        await afterNextRender(page);

        await expect(plus).not.toBeFocused();
    });
});
