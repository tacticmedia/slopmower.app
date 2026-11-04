import { lineNumber, location, openFile, waitForHighlighting } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { LARGE_FILE } from './support/files';
import { expect, test } from './support/test';

test.describe('DragSelection', () => {
    test('A drag held at the bottom edge of the window scrolls the page and selects lines past the first screen', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, LARGE_FILE);
        await waitForHighlighting(page, 3);
        const start = await lineNumber(page, 3).boundingBox();
        const viewport = page.viewportSize();
        if (null === start || null === viewport) {
            throw new Error('The line and the viewport have a size.');
        }
        const x = start.x + start.width / 2;

        await page.mouse.move(x, start.y + start.height / 2);
        await page.mouse.down();
        await page.mouse.move(x, viewport.height - 5, { steps: 5 });
        await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(viewport.height * 2);
        await page.mouse.up();

        const [, end] = /#L3-L(\d+)$/.exec(location(page)) ?? [];
        expect(Number(end)).toBeGreaterThan(viewport.height / 10);
    });
});
