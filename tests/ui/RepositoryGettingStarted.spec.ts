import { FakeRepositories } from './support/fakeRepositories';
import { expect, test } from './support/test';

const STEPS = '[data-testid="onboarding-steps"]';

test.describe('RepositoryGettingStarted', () => {
    test('Without an open review, the steps show step 2 of 4 after Start a review', async ({ page, api }) => {
        const repository = new FakeRepositories(api).addLocal('billing');

        await page.goto(`/repositories/${repository.id}`);

        await expect(page.locator('main h2')).toHaveText(['Start a review', 'Getting started, step 2 of 4']);
        const steps = page.locator(`${STEPS} [data-testid="onboarding-step"]`);
        expect(await steps.evaluateAll((items) => items.map((item) => item.getAttribute('data-state')))).toEqual(['done', 'current', 'upcoming', 'upcoming']);
        await expect(page.locator('[data-testid="review-list"]')).toHaveCount(0);
    });

    test('With an open review, the steps are absent and T1 is not read', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const repository = repositories.addLocal('billing');
        repositories.addReview(repository, { review: { title: 'Invoice rounding' } });

        await page.goto(`/repositories/${repository.id}`);

        await expect(page.locator('[data-testid="review-list"] table')).toContainText('Invoice rounding');
        await expect(page.locator(STEPS)).toHaveCount(0);
        expect(api.callsTo('GET', '/api/v1/tokens')).toHaveLength(0);
    });
});
