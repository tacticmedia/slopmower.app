import { openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
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
});
