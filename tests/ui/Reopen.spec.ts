import { commentBoxButton } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { clickRequestButton, DETAIL, DETAIL_NEXT_STEP, DETAIL_STATUS, DETAIL_THREAD, openRequest, REQUEST_ROWS, submitNoteDialog } from './support/requestScreens';
import { expect, test } from './support/test';

test.describe('Reopen', () => {
    test('Reopen with a note makes the request wait for the agent', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        review.agentClaims(request.id);
        review.agentMarksReady(request.id, 'The calculation is one line, so I left it.');
        await openRequest(page, request.id);
        await expect(page.locator(DETAIL_STATUS)).toHaveText('Ready for review');
        await expect(page.locator(DETAIL_NEXT_STEP)).toHaveAttribute('data-awaiting', 'user');

        await clickRequestButton(page, 'Reopen');
        await expect(page.getByRole('dialog').locator('textarea')).toBeVisible();
        await expect(page.getByRole('dialog').getByRole('button', { name: 'Reopen', exact: true }), 'Reopen needs a note.').toBeDisabled();
        await submitNoteDialog(page, 'Please extract it anyway.', 'Reopen');

        await expect(page.locator(DETAIL_STATUS)).toHaveText('Reopened');
        await expect(page.locator(DETAIL_NEXT_STEP)).toHaveAttribute('data-awaiting', 'agent');
        await expect(page.locator(DETAIL_THREAD)).toContainText('Please extract it anyway.');
        expect(api.bodies('POST', '/api/v1/requests/:requestId/reopen')).toEqual([{ note: 'Please extract it anyway.' }]);
    });

    test('An agent question shows under Waiting for you', async ({ page, api }) => {
        const review = new FakeReview(api);
        const asked = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        review.seedLines(SMALL_FILE, 3, 3, 'Is strict_types needed here?');
        review.agentClaims(asked.id);
        review.agentAsks(asked.id, 'Should the new method be public?');
        await page.goto(`/reviews/${review.id}/requests`);
        const rows = page.locator(REQUEST_ROWS);
        await expect(rows).toHaveCount(2);

        await page.locator('[data-testid="request-filters"]').getByRole('button', { name: 'Waiting for you', exact: true }).click();

        await expect(rows).toHaveCount(1);
        await expect(page).toHaveURL(/awaiting=user/);
        expect(api.callsTo('GET', '/api/v1/reviews/:reviewId/requests').at(-1)?.query.get('awaiting')).toBe('user');
        await expect(rows).toContainText('Extract the tax calculation.');
        await expect(rows).toContainText('Waiting for you');
    });

    test('A reply on the request detail hands the turn back to the agent', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        review.agentClaims(request.id);
        review.agentAsks(request.id, 'Should the new method be public?');
        await openRequest(page, request.id);
        await expect(page.locator(DETAIL_NEXT_STEP)).toHaveAttribute('data-awaiting', 'user');

        await page.locator(`${DETAIL} textarea[aria-label="Reply"]`).fill('No, keep it private.');
        await commentBoxButton(page, 'Reply').click();

        await expect(page.locator(DETAIL_NEXT_STEP)).toHaveAttribute('data-awaiting', 'agent');
        await expect(page.locator(DETAIL_THREAD)).toContainText('No, keep it private.');
        await expect(page.locator(DETAIL_STATUS)).toHaveText('In progress');
        expect(api.bodies('POST', '/api/v1/requests/:requestId/comments')).toEqual([{ body: 'No, keep it private.' }]);
    });
});
