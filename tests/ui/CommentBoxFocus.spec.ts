import { commentAndSubmit, dragFromPlus, focusedCommentBoxBelow, openFile, shiftClickLines, waitForHighlighting } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

const BELOW_LINE_9 = '[slot="annotation-9"]';

test.describe('CommentBoxFocus', () => {
    test('A comment box that opens below two new threads keeps the focus while the window scrolls to it', async ({ page, api }) => {
        await page.addInitScript(() => {
            const counter = window as unknown as { textareaBlurs: number };
            counter.textareaBlurs = 0;
            document.addEventListener('focusout', (event) => event.target instanceof HTMLTextAreaElement && counter.textareaBlurs++, true);
        });
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await waitForHighlighting(page, 9);
        for (const body of ['First thread.', 'Second thread.']) {
            await dragFromPlus(page, 5, 9);
            await commentAndSubmit(page, await focusedCommentBoxBelow(page, 9), body, BELOW_LINE_9);
        }
        await shiftClickLines(page, 5, 9);
        const blursBefore = await page.evaluate(() => (window as unknown as { textareaBlurs: number }).textareaBlurs);

        await dragFromPlus(page, 9, 9);
        await focusedCommentBoxBelow(page, 9);
        // The view lays out again in the frames after the scroll.
        await page.waitForTimeout(500);

        expect(await page.evaluate(() => (window as unknown as { textareaBlurs: number }).textareaBlurs)).toBe(blursBefore);
    });
});
