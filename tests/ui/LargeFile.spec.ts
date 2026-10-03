import { CODE_HOST } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { LARGE_FILE } from './support/files';
import { expect, test } from './support/test';

const MAXIMUM_RENDERED_ROWS = 300;

test.describe('LargeFile', () => {
    test('#L3000 is in the viewport and fewer than 300 rows are rendered', async ({ page, api }) => {
        const review = new FakeReview(api);

        await page.goto(review.fileUrl(LARGE_FILE, '#L3000'));

        const line = page.locator(`${CODE_HOST} [data-line="3000"][data-selected-line]`);
        await expect(line).toContainText('LINE_3000');
        // Inside both the window and the scroll container of the code view.
        await expect
            .poll(() =>
                line.evaluate((element) => {
                    const row = element.getBoundingClientRect();
                    const view = document.querySelector('[data-testid="code-view"]')?.getBoundingClientRect();

                    return undefined !== view && row.top >= Math.max(view.top, 0) && row.bottom <= Math.min(view.bottom, window.innerHeight);
                }),
            )
            .toBe(true);
        expect(await page.locator(`${CODE_HOST} [data-line]`).count()).toBeLessThan(MAXIMUM_RENDERED_ROWS);
    });
});
