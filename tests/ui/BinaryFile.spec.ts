import { CODE_HOST } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { BINARY_FILE } from './support/files';
import { expect, test } from './support/test';

test.describe('BinaryFile', () => {
    test('A binary file shows the placeholder instead of code', async ({ page, api }) => {
        const review = new FakeReview(api);

        await page.goto(review.fileUrl(BINARY_FILE));

        await expect(page.locator('[data-testid="file-placeholder"]')).toHaveText('Binary file not shown.');
        await expect(page.locator('[data-testid="file-header"]')).toContainText(BINARY_FILE);
        await expect(page.locator(CODE_HOST)).toHaveCount(0);
    });
});
