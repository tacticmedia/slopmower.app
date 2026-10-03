import { FakeRepositories } from './support/fakeRepositories';
import { expect, test } from './support/test';

const FORM = '[data-testid="start-review-form"]';

test.describe('ApiErrorDisplay', () => {
    test('Starting a review at an unknown ref shows the API message', async ({ page, api }) => {
        const repository = new FakeRepositories(api).addLocal('e2e-billing');
        await page.goto(`/repositories/${repository.id}`);
        await page.locator(`${FORM} input[name="ref"]`).fill('no-such-branch');

        await page.locator(`${FORM} button[type="submit"]`).click();

        await expect(page.locator(`${FORM} [data-testid="action-error"]`)).toHaveText('The commit no-such-branch is not in the server clone.');
        expect(api.bodies('POST', '/api/v1/repositories/:repositoryId/reviews')).toEqual([{ ref: 'no-such-branch', title: null }]);
    });
});
