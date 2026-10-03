import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { clickRequestButton, DETAIL, DETAIL_STATUS, openRequest, submitNoteDialog } from './support/requestScreens';
import { expect, test } from './support/test';

const CLAIMED_BY = `${DETAIL} [data-testid="claimed-by"]`;

test.describe('Unassign', () => {
    test('Unassign restores the status before the claim and clears claimed by', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        review.agentClaims(request.id);
        review.agentMarksReady(request.id, 'Extracted.');
        review.command(request.id, 'reopen', { note: 'The method is still inline.' });
        review.agentClaims(request.id);
        await openRequest(page, request.id);
        await expect(page.locator(DETAIL_STATUS)).toHaveText('In progress');
        await expect(page.locator(CLAIMED_BY)).toContainText('claude-code laptop');

        await clickRequestButton(page, 'Unassign');
        await submitNoteDialog(page, 'You seem stuck, I take this back.', 'Unassign');

        await expect(page.locator(DETAIL_STATUS)).toHaveText('Reopened');
        await expect(page.locator(CLAIMED_BY)).toHaveCount(0);
        expect(api.bodies('POST', '/api/v1/requests/:requestId/release')).toEqual([{ note: 'You seem stuck, I take this back.' }]);
    });
});
