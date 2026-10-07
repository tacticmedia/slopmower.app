import type { Locator } from '@playwright/test';
import { openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { codeViewFiles, LARGE_FILE } from './support/files';
import { expect, test } from './support/test';

/** Enough root files to make the tree taller than its column. */
const ROOT_FILES = 60;

/** The bottom padding of `main`, below the code layout. */
const MAIN_PADDING_PX = 24;

function insideWindow(locator: Locator): Promise<boolean> {
    return locator.evaluate((element) => {
        const { top, bottom } = element.getBoundingClientRect();

        return top >= 0 && bottom <= window.innerHeight;
    });
}

function bottom(locator: Locator): Promise<number> {
    return locator.evaluate((element) => element.getBoundingClientRect().bottom);
}

test.describe('FileViewLayout', () => {
    test('The file view fills the window below the headers', async ({ page, api }) => {
        const files = { ...codeViewFiles(), ...Object.fromEntries(Array.from({ length: ROOT_FILES }, (_, index) => [`notes-${index}.md`, '# Note\n'])) };
        const review = new FakeReview(api, { files });

        await openFile(page, review, LARGE_FILE);

        await expect(page.locator('[data-testid="file-header"]').getByRole('button', { name: 'Focus' })).toHaveAttribute('aria-pressed', 'false');
        await expect(page.getByRole('heading', { level: 1 })).toBeInViewport();
        await expect(page.getByRole('navigation', { name: 'Review' })).toBeInViewport();
        const tree = page.locator('aside:has([data-testid="file-tree"])');
        const codeView = page.locator('[data-testid="code-view"]');
        await expect.poll(() => insideWindow(tree)).toBe(true);
        await expect.poll(() => insideWindow(codeView)).toBe(true);
        expect(await bottom(tree)).toBeCloseTo((await page.evaluate(() => window.innerHeight)) - MAIN_PADDING_PX, -1);
        expect(await bottom(codeView)).toBeCloseTo(await bottom(tree), -1);
        expect(await tree.evaluate((element) => element.scrollHeight > element.clientHeight)).toBe(true);
        expect(await page.evaluate(() => document.scrollingElement?.scrollHeight === window.innerHeight)).toBe(true);
    });
});
