import type { CarryOverInput } from '../../assets/api/types/inputs';
import type { Request } from '../../assets/api/types/request';
import { carryOverResult, fixture } from './support/builders';
import { FakeRepositories } from './support/fakeRepositories';
import { json } from './support/mockApi';
import { expect, test } from './support/test';

const START_FORM = '[data-testid="start-review-form"]';
const CARRY_OVER = '/api/v1/reviews/:reviewId/carry-over';

test.describe('CarryOver', () => {
    test('A start with carry-over lists every source request with its outcome', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const repository = repositories.addLocal('e2e-carry-over');
        const source = repositories.addReview(repository, { review: { title: 'First review' } });
        api.on('POST', CARRY_OVER, ({ params, body }) => json('carry-over-result.schema.json', carryOverResult(params.reviewId ?? '', (body as CarryOverInput).fromReviewId)));
        await page.goto(`/repositories/${repository.id}`);

        await page.locator(`${START_FORM} select[name="fromReviewId"]`).selectOption({ label: 'First review' });
        await page.locator(`${START_FORM} button[type="submit"]`).click();

        const items = page.locator('[data-testid="carry-over-item"]');
        await expect(items.locator('[data-testid="carry-over-outcome"]')).toHaveText(['Not carried: in progress', 'Carried, the code changed', 'Carried', 'Not carried: its file is gone']);
        await expect(items.nth(1).getByRole('link')).toHaveAttribute('href', '/requests/01K6D4S0A1B2C3D4E5F6G7H8J9');
        const started = repositories.reviews.at(-1);
        await expect(page.getByRole('link', { name: 'Open the review' })).toHaveAttribute('href', `/reviews/${started?.id}`);
        expect(api.bodies('POST', CARRY_OVER)).toEqual([{ fromReviewId: source.id }]);
    });

    test('A carried thread links to its source request', async ({ page, api }) => {
        const carried = fixture<Request>('request.carried.json');
        api.on('GET', '/api/v1/requests/:requestId', ({ params }) => (params.requestId === carried.id ? json('request.schema.json', carried) : undefined));

        await page.goto(`/requests/${carried.id}`);

        await expect(page.locator('[data-testid="carried-from"]')).toHaveText('Carried from the source request');
        await expect(page.locator('[data-testid="carried-from"] a')).toHaveAttribute('href', '/requests/01K6B3P2A9QRSTVWXYZ0123457');
    });
});
