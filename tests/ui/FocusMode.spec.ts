import type { Locator, Page } from '@playwright/test';
import { COMPOSER, FILE_THREADS, openFile, waitForSymbols } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { codeViewFiles, LARGE_FILE, SMALL_FILE, SYMBOL_FILE } from './support/files';
import { apiError } from './support/mockApi';
import { expect, test } from './support/test';

/** Enough root files to make the tree taller than the window. */
const ROOT_FILES = 60;

const SHORTCUT = 'ControlOrMeta+Shift+Enter';

/** The name also holds the keys of the shortcut. */
function focusToggle(page: Page): Locator {
    return page.locator('[data-testid="file-header"]').getByRole('button', { name: 'Focus' });
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

    test('A file error and a tree error in focus mode keep the Focus toggle', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await focusToggle(page).click();
        const reviewNavigation = page.getByRole('navigation', { name: 'Review' });
        const errorToggle = page.getByRole('button', { name: 'Focus' });
        await expect(reviewNavigation).toBeHidden();

        await page.goto(review.fileUrl('src/Missing.php'));
        await expect(page.locator('[data-testid="route-error"]')).toContainText('Something went wrong');
        await expect(errorToggle).toBeVisible();
        api.on('GET', '/api/v1/reviews/:reviewId/tree', () => apiError(500, 'internal_error', 'The tree could not be listed.'));
        await page.goto(review.fileUrl(SMALL_FILE));
        await expect(page.locator('[data-testid="route-error"]')).toContainText('The tree could not be listed.');
        await errorToggle.click();

        await expect(reviewNavigation).toBeVisible();
    });

    test('The shortcut turns focus mode on and off', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);

        await page.keyboard.press(SHORTCUT);
        await expectFocusMode(page, true);

        await page.keyboard.press(SHORTCUT);
        await expectFocusMode(page, false);
    });

    test('The shortcut works while a file in the tree has the focus', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        const readme = page.locator('[data-testid="file-tree"] [data-path="README.md"]');
        await readme.click();
        await expect(page.locator('[data-testid="file-header"]')).toContainText('README.md');
        await expect(readme).toBeFocused();

        await page.keyboard.press(SHORTCUT);

        await expectFocusMode(page, true);
    });

    test('The shortcut in a dialog does nothing', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await page.getByRole('button', { name: 'Close review' }).click();
        await page.getByRole('dialog', { name: 'Close review' }).getByRole('button', { name: 'Cancel' }).focus();

        await page.keyboard.press(SHORTCUT);

        // The dialog title is a level 1 heading too.
        await expect(focusToggle(page)).toHaveAttribute('aria-pressed', 'false');
        await expect(page.getByRole('navigation', { name: 'Review' })).toBeVisible();
    });

    test('The shortcut in a comment box does nothing', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await page.getByRole('button', { name: 'Comment on file' }).click();
        const composer = page.locator(`${FILE_THREADS} ${COMPOSER}`);
        await expect(composer).toBeFocused();
        await page.keyboard.type('Draft');

        await page.keyboard.press(SHORTCUT);

        await expectFocusMode(page, false);
        // The comment box sends only on the shortcut without Shift.
        await expect(composer).toHaveValue('Draft');
    });

    test('The shortcut on the review page without an open file does nothing', async ({ page, api }) => {
        const review = new FakeReview(api);
        await page.goto(`/reviews/${review.id}`);
        await expect(page.getByText('Select a file in the tree.')).toBeVisible();

        await page.keyboard.press(SHORTCUT);
        await page.locator('[data-testid="file-tree"] [data-path="README.md"]').click();

        await expect(page.locator('[data-testid="file-header"]')).toContainText('README.md');
        await expectFocusMode(page, false);
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
