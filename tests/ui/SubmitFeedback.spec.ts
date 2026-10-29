import type { Page } from '@playwright/test';
import { COMPOSER, commentBoxButton, FILE_THREADS, openFile, SUBMIT_FEEDBACK, waitForSavedDraft, waitForSavedThread } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

const SUBMIT = '/api/v1/reviews/:reviewId/drafts/submit';
const FORM = 'form[data-testid="submit-feedback-form"]';

async function addFileDraft(page: Page, body: string): Promise<void> {
    await page.getByRole('button', { name: 'Comment on file', exact: true }).click();
    await page.locator(`${FILE_THREADS} ${COMPOSER}`).fill(body);
    await commentBoxButton(page, 'Add comment').click();
    await waitForSavedDraft(page, FILE_THREADS, body);
}

/** Decision D85. */
test.describe('SubmitFeedback', () => {
    test('The delivery choice goes with D5, and the next dialog starts from it', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await addFileDraft(page, 'Split this class.');
        await page.locator(SUBMIT_FEEDBACK).click();
        const form = page.locator(FORM);

        await form.getByLabel('Branch').selectOption('new_branch');
        await form.getByLabel('Pull request').selectOption('true');
        await form.getByRole('button', { name: 'Submit feedback', exact: true }).click();
        await waitForSavedThread(page, FILE_THREADS, 'Split this class.');
        await addFileDraft(page, 'Name the constants.');
        await page.locator(SUBMIT_FEEDBACK).click();

        await expect(form.getByLabel('Branch')).toHaveValue('new_branch');
        await expect(form.getByLabel('Pull request')).toHaveValue('true');
        expect(api.bodies('POST', SUBMIT)).toEqual([{ summary: null, delivery: { workspace: 'new_branch', pullRequest: true } }]);
    });

    test('A local repository has no pull request choice, and D5 sends none', async ({ page, api }) => {
        const review = new FakeReview(api, { review: { capabilities: { pullRequests: false } } });
        await openFile(page, review, SMALL_FILE);
        await addFileDraft(page, 'Split this class.');

        await page.locator(SUBMIT_FEEDBACK).click();
        const form = page.locator(FORM);
        await expect(form.getByLabel('Branch')).toHaveValue('');
        await expect(form.getByLabel('Pull request')).toHaveCount(0);
        await form.getByRole('button', { name: 'Submit feedback', exact: true }).click();

        await waitForSavedThread(page, FILE_THREADS, 'Split this class.');
        expect(api.bodies('POST', SUBMIT)).toEqual([{ summary: null, delivery: { workspace: null, pullRequest: null } }]);
    });
});
