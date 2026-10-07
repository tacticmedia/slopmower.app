import type { Page } from '@playwright/test';
import { commentBoxButton } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { REQUEST_ROWS } from './support/requestScreens';
import { expect, test } from './support/test';

const NEW_REQUEST_BOX = 'textarea[aria-label="New request"]';
const PENDING_DRAFTS = '[data-testid="pending-drafts"]';
const FINISH = '[data-testid="finish-review"]';

async function writeNewRequest(page: Page, review: FakeReview, body: string, button: string): Promise<void> {
    await page.goto(`/reviews/${review.id}/requests`);
    await page.getByRole('button', { name: 'New request', exact: true }).click();
    await page.locator(NEW_REQUEST_BOX).fill(body);
    await commentBoxButton(page, button).click();
}

test.describe('GeneralRequest', () => {
    test('New request opens a general request shown as General', async ({ page, api }) => {
        const review = new FakeReview(api);

        await writeNewRequest(page, review, 'Add a full-screen focus mode.', 'Add single comment');

        const row = page.locator(REQUEST_ROWS).filter({ hasText: 'Add a full-screen focus mode.' });
        await expect(row.locator('[data-testid="general-request"]')).toHaveText('General');
        await expect(page.locator(NEW_REQUEST_BOX)).toHaveCount(0);
        expect(api.bodies('POST', '/api/v1/reviews/:reviewId/requests')).toEqual([{ anchor: null, body: 'Add a full-screen focus mode.' }]);
    });

    test('A closed review has no New request button', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.close();

        await page.goto(`/reviews/${review.id}/requests`);

        await expect(page.locator('[data-testid="request-filters"]')).toBeVisible();
        await expect(page.getByRole('button', { name: 'New request', exact: true })).toHaveCount(0);
    });

    test('A general draft joins the batch', async ({ page, api }) => {
        const review = new FakeReview(api);

        await writeNewRequest(page, review, 'Wrap long lines.', 'Start a review');

        const draft = page.locator(`${PENDING_DRAFTS} [data-testid="draft"][data-draft-id]`);
        await expect(draft).toContainText('Pending');
        await expect(draft).toContainText('General');
        await expect(draft).toContainText('Wrap long lines.');
        await expect(page.locator(FINISH)).toContainText('1');
        expect(api.callsTo('POST', '/api/v1/reviews/:reviewId/requests'), 'A draft is not a request.').toEqual([]);

        await page.locator(FINISH).click();
        await page.locator('form[data-testid="finish-review-form"]').getByRole('button', { name: 'Submit review' }).click();

        await expect(page.locator(REQUEST_ROWS).filter({ hasText: 'Wrap long lines.' }).locator('[data-testid="general-request"]')).toHaveText('General');
        await expect(page.locator(PENDING_DRAFTS)).toHaveCount(0);
        expect(api.bodies('POST', '/api/v1/reviews/:reviewId/drafts')).toEqual([{ kind: 'new_request', anchor: null, body: 'Wrap long lines.' }]);
    });
});
