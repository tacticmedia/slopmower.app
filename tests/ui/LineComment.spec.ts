import { addSingleComment, CODE_HOST, dragFromPlus, focusedCommentBoxBelow, openFile, waitForHighlighting, waitForSavedThread } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

test.describe('LineComment', () => {
    test('A comment through + on line 10 is saved and shows on line 10 after a reload', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await waitForHighlighting(page, 10);

        await dragFromPlus(page, 10, 10);
        await addSingleComment(page, await focusedCommentBoxBelow(page, 10), 'Use a named constant.', '[slot="annotation-10"]');

        expect(api.bodies('POST', '/api/v1/reviews/:reviewId/requests')).toEqual([{ anchor: { kind: 'line', path: SMALL_FILE, range: { startLine: 10, endLine: 10 } }, body: 'Use a named constant.' }]);
        expect(await page.evaluate(() => location.hash), 'The submit clears the selection and its line hash.').toBe('');

        await page.reload();
        await waitForSavedThread(page, '[slot="annotation-10"]', 'Use a named constant.');
        await expect(page.locator(`${CODE_HOST} slot[name="annotation-10"]`)).toHaveCount(1);
    });
});
