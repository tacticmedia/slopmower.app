import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

const CPU_SLOWDOWN = 4;
const LATENCY_MS = 100;
const SMALL_FILE_LINES = 13;
const SLOW_CLIENT_TIMEOUT_MS = 30_000;

test.describe('EarlyAnnotation', () => {
    test('The code view renders after an early annotation change on a slow client', async ({ page, api }) => {
        test.setTimeout(90_000);
        const review = new FakeReview(api);
        const cdp = await page.context().newCDPSession(page);
        await cdp.send('Emulation.setCPUThrottlingRate', { rate: CPU_SLOWDOWN });
        await cdp.send('Network.enable');
        await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: LATENCY_MS, downloadThroughput: -1, uploadThroughput: -1 });
        // The worker loads the PHP grammar for the first highlight. Holding it keeps the plain rows until the change.
        let releaseGrammar = (): void => undefined;
        const grammarHeld = new Promise<void>((resolve) => {
            releaseGrammar = resolve;
        });
        let grammarRequests = 0;
        await page.context().route(/\/assets\/php-[\w-]+\.js$/, async (route) => {
            grammarRequests += 1;
            await grammarHeld;
            await route.continue();
        });
        await page.goto(review.fileUrl(SMALL_FILE));
        await expect(page.locator('diffs-container [data-line]')).toHaveCount(SMALL_FILE_LINES, { timeout: SLOW_CLIENT_TIMEOUT_MS });
        await expect.poll(() => grammarRequests, { message: 'The worker requests the held PHP grammar chunk.', timeout: SLOW_CLIENT_TIMEOUT_MS }).toBeGreaterThan(0);
        await expect(page.locator('diffs-container [data-line] span[style]')).toHaveCount(0);

        await page.getByRole('button', { name: 'Comment on lines' }).click();
        await page.locator('input[name="start"]').fill('3');
        await page.locator('input[name="end"]').fill('4');
        await page.locator('input[name="end"]').press('Enter');
        await expect(page.locator('[slot="annotation-4"] textarea')).toBeAttached();
        releaseGrammar();

        await expect(page.locator('diffs-container [data-line="1"] span[style]').first()).toBeAttached({ timeout: SLOW_CLIENT_TIMEOUT_MS });
        await expect(page.locator('diffs-container [data-line]')).toHaveCount(SMALL_FILE_LINES);
        await expect(page.locator('[slot="annotation-4"] textarea')).toBeVisible();
    });
});
