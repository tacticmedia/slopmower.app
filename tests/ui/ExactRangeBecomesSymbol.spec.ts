import { addSingleComment, commentBoxHeader, dragFromPlus, focusedCommentBoxBelow, openFile, shiftClickLines, waitForHighlighting, waitForSymbols } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { ROUND_FQN, SYMBOL_FILE } from './support/files';
import { expect, test } from './support/test';

test.describe('ExactRangeBecomesSymbol', () => {
    test("Selecting exactly a method's range creates a symbol anchor with its FQN", async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SYMBOL_FILE);
        await waitForSymbols(page);
        await waitForHighlighting(page, 17);

        await shiftClickLines(page, 11, 17);
        await dragFromPlus(page, 17, 17);
        const textarea = await focusedCommentBoxBelow(page, 17);
        await expect(commentBoxHeader(page, 17)).toHaveText(`Comment on method ${ROUND_FQN}`);
        await addSingleComment(page, textarea, 'Round half to even.', '[slot="annotation-17"]');

        expect(api.bodies('POST', '/api/v1/reviews/:reviewId/requests')).toEqual([{ anchor: { kind: 'symbol', path: SYMBOL_FILE, symbol: { fqn: ROUND_FQN } }, body: 'Round half to even.', requireApproval: true }]);
    });
});
