import { addSingleComment, dragFromPlus, focusedCommentBoxBelow, openFile, shiftClickLines, waitForHighlighting } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

const BELOW_LINE_9 = '[slot="annotation-9"]';

test.describe('RangeComment', () => {
    test('A drag from 5 to 9, a drag from 9 to 5, and click with shift-click all give the anchor 5 to 9', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await waitForHighlighting(page, 9);

        await dragFromPlus(page, 5, 9);
        await addSingleComment(page, await focusedCommentBoxBelow(page, 9), 'Dragged down.', BELOW_LINE_9);

        await dragFromPlus(page, 9, 5);
        await addSingleComment(page, await focusedCommentBoxBelow(page, 9), 'Dragged up.', BELOW_LINE_9);

        await shiftClickLines(page, 5, 9);
        // The selection keeps the "+" on its last line, gap G1.
        await dragFromPlus(page, 9, 9);
        await addSingleComment(page, await focusedCommentBoxBelow(page, 9), 'Shift-clicked.', BELOW_LINE_9);

        const range = { kind: 'range', path: SMALL_FILE, range: { startLine: 5, endLine: 9 } };
        expect(api.bodies('POST', '/api/v1/reviews/:reviewId/requests')).toEqual([
            { anchor: range, body: 'Dragged down.', requireApproval: true },
            { anchor: range, body: 'Dragged up.', requireApproval: true },
            { anchor: range, body: 'Shift-clicked.', requireApproval: true },
        ]);
    });
});
