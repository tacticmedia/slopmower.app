import type { Page } from '@playwright/test';
import { commentBoxButton, FILE_THREADS, openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

function askForPlan(page: Page, container: string) {
    return page.locator(`${container} [data-testid="comment-box"]`).getByRole('checkbox', { name: 'Ask for a plan first', exact: true });
}

test.describe('AskForPlan', () => {
    test('The checkbox of a new request is checked by default, and Q2 carries it', async ({ page, api }) => {
        const review = new FakeReview(api);
        await page.goto(`/reviews/${review.id}/requests`);

        await page.getByRole('button', { name: 'New request', exact: true }).click();

        await expect(askForPlan(page, '[data-testid="request-filters"]')).toBeChecked();
        await page.locator('textarea[aria-label="New request"]').fill('Add a CSV export.');
        await commentBoxButton(page, 'Add single comment').click();
        await expect.poll(() => api.bodies('POST', '/api/v1/reviews/:reviewId/requests')).toEqual([{ anchor: null, body: 'Add a CSV export.', requireApproval: true }]);
    });

    test('An unchecked box sends false in D2', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);

        await page.getByRole('button', { name: 'Comment on file', exact: true }).click();
        await askForPlan(page, FILE_THREADS).uncheck();
        await page.locator(`${FILE_THREADS} textarea`).fill('Split this class.');
        await commentBoxButton(page, 'Start a review').click();

        await expect(page.locator(`${FILE_THREADS} [data-testid="draft"][data-draft-id]`)).toContainText('Split this class.');
        expect(api.bodies('POST', '/api/v1/reviews/:reviewId/drafts')).toEqual([{ kind: 'new_request', anchor: { kind: 'file', path: SMALL_FILE }, body: 'Split this class.', requireApproval: false }]);
    });
});
