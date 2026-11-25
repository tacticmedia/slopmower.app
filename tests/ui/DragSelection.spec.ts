import type { Page } from '@playwright/test';
import { lineNumber, location, openFile, waitForHighlighting } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { LARGE_FILE } from './support/files';
import { expect, test } from './support/test';

const CPU_SLOWDOWN = 4;
const SLOW_CLIENT_TIMEOUT_MS = 30_000;

test.describe('DragSelection', () => {
    test('A drag held at the bottom edge of the window scrolls the page and selects lines past the first screen', async ({ page, api }) => {
        await openFile(page, new FakeReview(api), LARGE_FILE);
        await waitForHighlighting(page, 3);

        await dragPastFirstScreen(page);
    });

    test('A drag held at the bottom edge of the window keeps scrolling and selecting on a slow client', async ({ page, api }) => {
        test.setTimeout(90_000);
        await openFile(page, new FakeReview(api), LARGE_FILE);
        await waitForHighlighting(page, 3);
        const cdp = await page.context().newCDPSession(page);
        await cdp.send('Emulation.setCPUThrottlingRate', { rate: CPU_SLOWDOWN });

        await dragPastFirstScreen(page, SLOW_CLIENT_TIMEOUT_MS);
    });
});

/** Presses line 3, holds the pointer at the bottom edge until the page is two screens down, and releases. */
async function dragPastFirstScreen(page: Page, scrollTimeoutMs?: number): Promise<void> {
    const start = await lineNumber(page, 3).boundingBox();
    const viewport = page.viewportSize();
    if (null === start || null === viewport) {
        throw new Error('The line and the viewport have a size.');
    }
    const x = start.x + start.width / 2;

    await page.mouse.move(x, start.y + start.height / 2);
    await page.mouse.down();
    await page.mouse.move(x, viewport.height - 5, { steps: 5 });
    await expect.poll(() => page.evaluate(() => window.scrollY), { timeout: scrollTimeoutMs }).toBeGreaterThan(viewport.height * 2);
    await page.mouse.up();

    const [, end] = /#L3-L(\d+)$/.exec(location(page)) ?? [];
    expect(Number(end)).toBeGreaterThan(viewport.height / 10);
}
