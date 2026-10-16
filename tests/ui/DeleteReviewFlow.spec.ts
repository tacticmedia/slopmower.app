import { FakeRepositories } from './support/fakeRepositories';
import { chooseReviewAction } from './support/reviewHeader';
import { expect, test } from './support/test';

const REVIEW_LIST = '[data-testid="review-list"]';

test.describe('DeleteReviewFlow', () => {
    test('Confirming the dialog deletes the review and shows the repository page without it', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const repository = repositories.addLocal('e2e-reviews');
        repositories.addReview(repository, { review: { title: 'Kept review' } });
        const deleted = repositories.addReview(repository, { review: { title: 'Deleted review' } });
        await page.goto(`/reviews/${deleted.id}`);

        await chooseReviewAction(page, 'Delete review');
        await page.locator('[role="dialog"] button[type="submit"]').click();

        await expect(page).toHaveURL(`/repositories/${repository.id}`);
        await expect(page.locator(`${REVIEW_LIST} table`)).toContainText('Kept review');
        await expect(page.locator(`${REVIEW_LIST} table`)).not.toContainText('Deleted review');
        expect(api.callsTo('DELETE', '/api/v1/reviews/:reviewId').map(({ params }) => params.reviewId)).toEqual([deleted.id]);
    });
});
