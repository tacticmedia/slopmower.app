import { FakeReview } from './support/fakeReview';
import { clickRequestButton, DETAIL, DETAIL_THREAD, openRequest } from './support/requestScreens';
import { expect, test } from './support/test';

const PLAN_REQUIRED = `${DETAIL} [data-testid="plan-required"]`;

test.describe('PlanRequirement', () => {
    test('The button sends Q18 with the new value, and Plan required follows it', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedGeneral('Add a CSV export of the invoice totals.');
        review.agentClaims(request.id);
        await openRequest(page, request.id);
        await expect(page.locator(PLAN_REQUIRED)).toHaveCount(0);

        await clickRequestButton(page, 'Ask for a plan first');

        await expect(page.locator(PLAN_REQUIRED)).toHaveText('Plan required');
        await expect(page.locator(DETAIL_THREAD)).toContainText('asked for a plan first');

        await clickRequestButton(page, "Don't ask for a plan");

        await expect(page.locator(PLAN_REQUIRED)).toHaveCount(0);
        await expect(page.locator(DETAIL_THREAD)).toContainText('no longer asks for a plan');
        expect(api.bodies('POST', '/api/v1/requests/:requestId/plan-requirement')).toEqual([{ requireApproval: true }, { requireApproval: false }]);
    });
});
