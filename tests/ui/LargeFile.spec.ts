import { CODE_HOST, openFile, turnOnWrap } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { LARGE_FILE, SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

const MAXIMUM_RENDERED_ROWS = 300;

test.describe('LargeFile', () => {
    for (const wrap of [false, true]) {
        for (const focus of [false, true]) {
            const modes = [wrap ? 'wrap' : null, focus ? 'focus' : null].filter((mode) => null !== mode);
            test(`#L3000 is in the viewport and fewer than 300 rows are rendered${0 === modes.length ? '' : ` in ${modes.join(' and ')} mode`}`, async ({ page, api }) => {
                const review = new FakeReview(api);
                if (focus) {
                    await page.addInitScript(() => localStorage.setItem('holistic-review.focus', '1'));
                }
                if (wrap) {
                    await openFile(page, review, SMALL_FILE);
                    await turnOnWrap(page);
                }

                await page.goto(review.fileUrl(LARGE_FILE, '#L3000'));
                if (wrap) {
                    await expect(page.locator(`${CODE_HOST} pre[data-overflow="wrap"]`)).toHaveCount(1);
                }
                await expect(page.getByRole('button', { name: 'Focus', exact: true })).toHaveAttribute('aria-pressed', String(focus));

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
        }
    }
});
