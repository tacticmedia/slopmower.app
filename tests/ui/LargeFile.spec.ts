import type { Page } from '@playwright/test';
import { CODE_HOST, openFile, turnOnWrap } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { LARGE_FILE, SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

const MAXIMUM_RENDERED_ROWS = 300;

const SLOW_WORKER_MS = 3000;

/** The window is at rest after this many animation frames without a move. */
const REST_FRAMES = 5;

/** Without the worker pool of the page, the view has no rows, so the scroll to the line goes on. */
async function holdWorker(page: Page, release: () => Promise<void>): Promise<void> {
    await page.context().route(/\/assets\/worker-[\w-]+\.js$/, async (route) => {
        await release();
        await route.continue();
    });
}

async function expectBelowFileToolbar(page: Page, lineNumber: number): Promise<void> {
    const line = page.locator(`${CODE_HOST} [data-line="${lineNumber}"][data-selected-line]`);
    await expect(line).toContainText(`LINE_${lineNumber}`);
    await expect
        .poll(() =>
            line.evaluate((element) => {
                const row = element.getBoundingClientRect();
                const toolbar = document.querySelector('[data-testid="file-toolbar"]')?.getBoundingClientRect();

                return undefined !== toolbar && row.top >= toolbar.bottom && row.bottom <= window.innerHeight;
            }),
        )
        .toBe(true);
}

function windowAtRest(page: Page): Promise<void> {
    return page.evaluate(
        (frames) =>
            new Promise<void>((resolve) => {
                let lastY = window.scrollY;
                let restFrames = 0;
                const watch = (): void => {
                    restFrames = window.scrollY === lastY ? restFrames + 1 : 0;
                    lastY = window.scrollY;
                    if (restFrames < frames) {
                        requestAnimationFrame(watch);
                    } else {
                        resolve();
                    }
                };
                requestAnimationFrame(watch);
            }),
        REST_FRAMES,
    );
}

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

            await expectBelowFileToolbar(page, 3000);
            expect(await page.locator(`${CODE_HOST} [data-line]`).count()).toBeLessThan(MAXIMUM_RENDERED_ROWS);
        });
    }

    test('#L3000 is in the window below the file toolbar when the worker pool starts after 3 s', async ({ page, api }) => {
        const review = new FakeReview(api);
        await holdWorker(page, () => new Promise((resolve) => setTimeout(resolve, SLOW_WORKER_MS)));

        await page.goto(review.fileUrl(LARGE_FILE, '#L3000'));

        await expectBelowFileToolbar(page, 3000);
    });

    test('The scroll to #L10-L200, a range taller than the window, ends once line 10 is below the file toolbar', async ({ page, api }) => {
        const review = new FakeReview(api);
        await page.goto(review.fileUrl(LARGE_FILE, '#L10-L200'));
        await expectBelowFileToolbar(page, 10);
        await windowAtRest(page);

        // Not user input, like a drag of the scrollbar.
        const scrolledY = await page.evaluate(() => {
            window.scrollBy(0, 400);

            return window.scrollY;
        });
        await windowAtRest(page);

        expect(await page.evaluate(() => window.scrollY)).toBe(scrolledY);
    });

    test('A scroll of the user during the scroll to #L3000 is kept', async ({ page, api }) => {
        const review = new FakeReview(api);
        let releaseWorker = (): void => undefined;
        const workerHeld = new Promise<void>((resolve) => {
            releaseWorker = resolve;
        });
        await holdWorker(page, () => workerHeld);
        await page.goto(review.fileUrl(LARGE_FILE, '#L3000'));
        await page.waitForFunction(() => window.scrollY > 0, undefined, { polling: 'raf' });

        await page.keyboard.press('Home');
        releaseWorker();

        await expect(page.locator(`${CODE_HOST} [data-line]`).first()).toBeAttached();
        await expect.poll(() => page.evaluate(() => window.scrollY), 'The window stays at the top, where the user scrolled.').toBe(0);
    });
});
