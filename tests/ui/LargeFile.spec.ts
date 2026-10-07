import { CODE_HOST, openFile, turnOnWrap } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { LARGE_FILE, SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

const MAXIMUM_RENDERED_ROWS = 300;

test.describe('LargeFile', () => {
    for (const wrap of [false, true]) {
        test(`#L3000 is in the window below the file toolbar and fewer than 300 rows are rendered${wrap ? ' in wrap mode' : ''}`, async ({ page, api }) => {
            const review = new FakeReview(api);
            if (wrap) {
                await openFile(page, review, SMALL_FILE);
                await turnOnWrap(page);
            }

            await page.goto(review.fileUrl(LARGE_FILE, '#L3000'));
            if (wrap) {
                await expect(page.locator(`${CODE_HOST} pre[data-overflow="wrap"]`)).toHaveCount(1);
            }

            const line = page.locator(`${CODE_HOST} [data-line="3000"][data-selected-line]`);
            await expect(line).toContainText('LINE_3000');
            await expect
                .poll(() =>
                    line.evaluate((element) => {
                        const row = element.getBoundingClientRect();
                        const toolbar = document.querySelector('[data-testid="file-toolbar"]')?.getBoundingClientRect();

                        return undefined !== toolbar && row.top >= toolbar.bottom && row.bottom <= window.innerHeight;
                    }),
                )
                .toBe(true);
            expect(await page.locator(`${CODE_HOST} [data-line]`).count()).toBeLessThan(MAXIMUM_RENDERED_ROWS);
        });
    }
});
