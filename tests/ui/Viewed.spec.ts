import { location, openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE, SYMBOL_FILE } from './support/files';
import { apiError } from './support/mockApi';
import { expect, test } from './support/test';

const CHECKBOX = '[data-testid="file-header"] [data-testid="viewed-checkbox"]';
const TREE_ITEM = `[data-testid="file-tree"] [data-path="${SMALL_FILE}"]`;
const TREE_MARK = `${TREE_ITEM} [data-testid="viewed-mark"]`;

test.describe('Viewed', () => {
    test('A file marked as viewed shows the mark in the tree after a reload', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await expect(page.locator(TREE_ITEM)).toBeVisible();
        await expect(page.locator(TREE_MARK)).toHaveCount(0);

        await page.locator(CHECKBOX).click();
        await expect(page.locator(TREE_MARK)).toBeVisible();
        expect(api.bodies('PUT', '/api/v1/reviews/:reviewId/viewed-files')).toEqual([{ path: SMALL_FILE, viewed: true }]);

        await page.reload();
        await expect(page.locator(TREE_MARK)).toBeVisible();
        await expect(page.locator(CHECKBOX)).toBeChecked();
    });

    test('A write does not load the tree of the review commit again', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await expect(page.locator(TREE_ITEM)).toBeVisible();
        const treeReads = api.callsTo('GET', '/api/v1/reviews/:reviewId/tree').length;

        await page.locator(CHECKBOX).click();
        await expect(page.locator(TREE_MARK)).toBeVisible();

        expect(api.callsTo('GET', '/api/v1/reviews/:reviewId/tree')).toHaveLength(treeReads);
    });

    test('A failed mark stays with its file and is gone after opening another file', async ({ page, api }) => {
        const review = new FakeReview(api);
        api.on('PUT', '/api/v1/reviews/:reviewId/viewed-files', () => apiError(503, 'internal_error', 'The server is unavailable.'));
        await openFile(page, review, SMALL_FILE);

        await page.locator(CHECKBOX).click();
        await expect(page.getByTestId('action-error')).toHaveText('The server is unavailable.');
        await page.locator(`[data-testid="file-tree"] [data-path="${SYMBOL_FILE}"]`).click();
        await expect.poll(() => location(page)).toBe(review.fileUrl(SYMBOL_FILE));

        await expect(page.getByTestId('action-error')).toHaveCount(0);
        api.takeProblems();
    });
});
