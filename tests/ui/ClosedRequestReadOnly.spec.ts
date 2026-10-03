import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { DETAIL, DETAIL_STATUS, openRequest } from './support/requestScreens';
import { expect, test } from './support/test';

test.describe('ClosedRequestReadOnly', () => {
    test('A request of a closed review shows no action buttons and no reply box', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        review.close();

        await openRequest(page, request.id);

        await expect(page.locator('[data-testid="review-closed"]')).toBeVisible();
        await expect(page.locator(DETAIL_STATUS)).toHaveText('Open');
        await expect(page.locator('[data-testid="request-actions"]')).toHaveCount(0);
        // The tab navigation always renders its hidden "More" button.
        await expect(page.locator(`${DETAIL} > :not(nav) button`)).toHaveCount(0);
        await expect(page.locator(`${DETAIL} textarea`)).toHaveCount(0);
    });
});
