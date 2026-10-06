import type { Locator, Page } from '@playwright/test';
import { COMPOSER, FILE_THREADS, openFile, waitForSymbols } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { codeViewFiles, LARGE_FILE, SMALL_FILE, SYMBOL_FILE } from './support/files';
import { expect, test } from './support/test';

/** Enough root files to make the tree taller than the window. */
const ROOT_FILES = 60;

function focusToggle(page: Page): Locator {
    return page.locator('[data-testid="file-header"]').getByRole('button', { name: 'Focus', exact: true });
}

/** With `closed`, also the closed banner. */
function headers(page: Page, closed: boolean): Locator[] {
    const locators = [page.locator('[data-testid="current-user"]'), page.getByRole('heading', { level: 1 }), page.getByRole('navigation', { name: 'Review' })];

    return closed ? [...locators, page.locator('[data-testid="review-closed"]')] : locators;
}

async function expectFocusMode(page: Page, on: boolean, closed = false): Promise<void> {
    await expect(focusToggle(page)).toHaveAttribute('aria-pressed', String(on));
    for (const header of headers(page, closed)) {
        await (on ? expect(header).toBeHidden() : expect(header).toBeVisible());
    }
}

function insideWindow(locator: Locator): Promise<boolean> {
    return locator.evaluate((element) => {
        const { top, bottom } = element.getBoundingClientRect();

        return top >= 0 && bottom <= window.innerHeight;
    });
}

function bottom(locator: Locator): Promise<number> {
    return locator.evaluate((element) => element.getBoundingClientRect().bottom);
}

test.describe('FocusMode', () => {
    test('Focus hides the headers and fills the window', async ({ page, api }) => {
        const files = { ...codeViewFiles(), ...Object.fromEntries(Array.from({ length: ROOT_FILES }, (_, index) => [`notes-${index}.md`, '# Note\n'])) };
        const review = new FakeReview(api, { files });
        await openFile(page, review, LARGE_FILE);

        await focusToggle(page).click();

        await expectFocusMode(page, true);
        const tree = page.locator('aside:has([data-testid="file-tree"])');
        const codeView = page.locator('[data-testid="code-view"]');
        await expect.poll(() => insideWindow(tree)).toBe(true);
        await expect.poll(() => insideWindow(codeView)).toBe(true);
        // The code view takes the rest of the file column.
        expect(await bottom(codeView)).toBeCloseTo(await bottom(tree), -1);
        expect(await tree.evaluate((element) => element.scrollHeight > element.clientHeight)).toBe(true);
        expect(await page.evaluate(() => document.scrollingElement?.scrollHeight === window.innerHeight)).toBe(true);
    });

    test('Focus survives navigation and reload', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, LARGE_FILE);
        await focusToggle(page).click();

        await page.locator('[data-testid="file-tree"] [data-path="README.md"]').click();
        await expect(page.locator('[data-testid="file-header"]')).toContainText('README.md');
        await expectFocusMode(page, true);

        await page.reload();
        await expect(page.locator('[data-testid="file-header"]')).toContainText('README.md');
        await expectFocusMode(page, true);
    });

    test('Escape leaves focus mode', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.close();
        await openFile(page, review, SMALL_FILE);
        await focusToggle(page).click();
        await expectFocusMode(page, true, true);

        await page.keyboard.press('Escape');

        await expectFocusMode(page, false, true);
    });

    test('Escape in a comment box keeps focus mode', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await focusToggle(page).click();
        await page.getByRole('button', { name: 'Comment on file' }).click();
        await expect(page.locator(`${FILE_THREADS} ${COMPOSER}`)).toBeFocused();

        await page.keyboard.press('Escape');

        await expectFocusMode(page, true);
    });

    test('Escape in the Symbols menu closes only the menu', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SYMBOL_FILE);
        await focusToggle(page).click();
        await waitForSymbols(page);
        await page.locator('[data-testid="file-header"]').getByRole('button', { name: 'Symbols' }).click();
        const filter = page.getByRole('combobox', { name: 'Filter symbols' });
        await expect(filter).toBeFocused();

        await page.keyboard.press('Escape');

        await expect(filter).toHaveCount(0);
        await expectFocusMode(page, true);
    });
});
