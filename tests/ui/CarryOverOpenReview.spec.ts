import type { Page } from '@playwright/test';
import type { CarryOverInput } from '../../assets/api/types/inputs';
import { carryOverResult, now } from './support/builders';
import { FakeRepositories } from './support/fakeRepositories';
import { apiError, json } from './support/mockApi';
import { expect, test } from './support/test';

const DIALOG = '[role="dialog"]';
const CARRY_OVER = '/api/v1/reviews/:reviewId/carry-over';

function openDialog(page: Page) {
    return page.locator('header').getByRole('button', { name: 'Carry over requests' }).click();
}

test.describe('CarryOverOpenReview', () => {
    test('The dialog lists the other open and closed reviews of the repository only', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const repository = repositories.addLocal('e2e-carry-over');
        const target = repositories.addReview(repository, { review: { title: 'Target review', createdAt: now() } });
        repositories.addReview(repository, { review: { title: 'Open review', createdAt: now(-60) } });
        repositories.addReview(repository, { review: { title: 'Closed review', createdAt: now(-120) } }).close();
        repositories.addReview(repositories.addLocal('e2e-other'), { review: { title: 'Review of another repository' } });
        await page.goto(`/reviews/${target.id}`);

        await openDialog(page);

        await expect(page.locator(`${DIALOG} select[name="fromReviewId"] option`)).toHaveText(['Open review', 'Closed review (closed)']);
    });

    test('Choosing a source sends V15 and shows each outcome without a link to the review', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const repository = repositories.addLocal('e2e-carry-over');
        const target = repositories.addReview(repository, { review: { title: 'Target review' } });
        repositories.addReview(repository, { review: { title: 'Newer source' } });
        const source = repositories.addReview(repository, { review: { title: 'Older source' } });
        api.on('POST', CARRY_OVER, ({ params, body }) => json('carry-over-result.schema.json', carryOverResult(params.reviewId ?? '', (body as CarryOverInput).fromReviewId)));
        await page.goto(`/reviews/${target.id}`);

        await openDialog(page);
        await page.locator(`${DIALOG} select[name="fromReviewId"]`).selectOption({ label: 'Older source' });
        await page.locator(`${DIALOG} button[type="submit"]`).click();

        const outcomes = page.locator(`${DIALOG} [data-testid="carry-over-outcome"]`);
        await expect(outcomes).toHaveText(['Not carried: in progress', 'Carried, the code changed', 'Carried', 'Not carried: its file is gone']);
        await expect(page.getByRole('link', { name: 'Open the review' })).toHaveCount(0);
        expect(api.callsTo('POST', CARRY_OVER).map(({ params, body }) => [params.reviewId, body])).toEqual([[target.id, { fromReviewId: source.id }]]);
    });

    test('A 422 answer shows its message in the dialog', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const repository = repositories.addLocal('e2e-carry-over');
        const target = repositories.addReview(repository, { review: { title: 'Target review' } });
        repositories.addReview(repository, { review: { title: 'Source review' } });
        const message = 'Invalid input. fromReviewId: Must name a review of the same repository.';
        api.on('POST', CARRY_OVER, () => apiError(422, 'invalid_input', message, { violations: [{ field: 'fromReviewId', message: 'Must name a review of the same repository.' }] }));
        await page.goto(`/reviews/${target.id}`);

        await openDialog(page);
        await page.locator(`${DIALOG} button[type="submit"]`).click();

        await expect(page.locator(`${DIALOG} [data-testid="action-error"]`)).toHaveText(message);
        await expect(page.locator(`${DIALOG} select[name="fromReviewId"]`)).toBeVisible();
    });
});
