import { commentBoxHeader, focusedCommentBoxBelow, keyboardComment, openFile, tabToButton, waitForSymbols } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { ROUND_FQN, SYMBOL_FILE } from './support/files';
import { expect, test } from './support/test';

/** Decision D31. Every step uses the keyboard, none uses the mouse. */
test.describe('KeyboardOnlyCommenting', () => {
    test('The Symbols menu opens, filters, and anchors a comment to a method with the keyboard only', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SYMBOL_FILE);
        await waitForSymbols(page);

        await tabToButton(page, 'Symbols');
        await page.keyboard.press('Enter');
        await expect.poll(() => page.evaluate(() => document.activeElement?.getAttribute('aria-label'))).toBe('Filter symbols');
        await page.keyboard.type('round(');
        await expect(page.getByRole('option'), 'The filter leaves round() alone.').toHaveCount(1);
        await expect(page.getByRole('option')).toHaveText(/^round\(\)/);
        await page.keyboard.press('Enter');
        await focusedCommentBoxBelow(page, 17);
        await expect(commentBoxHeader(page, 17)).toHaveText(`Comment on method ${ROUND_FQN}`);
        await keyboardComment(page, 'Round half to even.', '[slot="annotation-17"]');

        expect(api.bodies('POST', '/api/v1/reviews/:reviewId/requests')).toEqual([{ anchor: { kind: 'symbol', path: SYMBOL_FILE, symbol: { fqn: ROUND_FQN } }, body: 'Round half to even.' }]);
    });
});
