import type { Locator, Page } from '@playwright/test';
import { COMPOSER, commentBoxButton, dragFromPlus, FILE_THREADS, focusedCommentBoxBelow, openFile, waitForHighlighting, waitForSavedThread } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { chooseReviewAction } from './support/reviewHeader';
import { SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

const SAVED_DRAFT = '[data-testid="draft"][data-draft-id]';
const FINISH = '[data-testid="finish-review"]';
const BELOW_LINE_10 = '[slot="annotation-10"]';
const DRAFTS = '/api/v1/reviews/:reviewId/drafts';

/** Opens a file comment, types the text, and clicks the review button. */
async function commentOnFile(page: Page, body: string, reviewButton: string): Promise<void> {
    await page.getByRole('button', { name: 'Comment on file' }).click();
    const textarea = page.locator(`${FILE_THREADS} ${COMPOSER}`);
    await expect(textarea).toBeVisible();
    await addReviewComment(page, textarea, body, reviewButton, FILE_THREADS);
}

/** Types into the comment box, clicks "Start a review" or "Add review comment", then waits for the saved draft. */
async function addReviewComment(page: Page, textarea: Locator, body: string, button: string, container: string): Promise<void> {
    await textarea.fill(body);
    await commentBoxButton(page, button).click();
    await waitForDraft(page, body, container);
}

async function waitForDraft(page: Page, body: string, container = FILE_THREADS): Promise<void> {
    await expect(page.locator(`${container} ${SAVED_DRAFT}`).filter({ hasText: body }).filter({ hasText: 'Pending' })).toHaveCount(1);
}

async function chooseDraftAction(page: Page, body: string, action: string): Promise<void> {
    await page.locator('article[data-testid="draft"]').filter({ hasText: body }).locator('button[aria-haspopup="true"]').click();
    await page.getByRole('menuitem', { name: action, exact: true }).click();
}

test.describe('BatchReview', () => {
    test('Two drafts submitted with a summary appear as two threads without the Pending label', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await commentOnFile(page, 'Split this class.', 'Start a review');
        await waitForHighlighting(page, 10);
        await dragFromPlus(page, 10, 10);
        await addReviewComment(page, await focusedCommentBoxBelow(page, 10), 'Use a named constant.', 'Add review comment', BELOW_LINE_10);
        await expect(page.locator(FINISH)).toContainText('2');
        expect(api.callsTo('POST', '/api/v1/reviews/:reviewId/requests'), 'Drafts are not requests.').toEqual([]);

        await page.locator(FINISH).click();
        await page.locator('[data-testid="finish-review-form"] textarea[name="summary"]').fill('First pass.');
        await page.locator('form[data-testid="finish-review-form"]').getByRole('button', { name: 'Submit review' }).click();

        await waitForSavedThread(page, FILE_THREADS, 'Split this class.');
        await waitForSavedThread(page, BELOW_LINE_10, 'Use a named constant.');
        await expect(page.locator(FINISH)).toHaveCount(0);
        await expect(page.locator('[data-testid="pending-label"]')).toHaveCount(0);
        expect(api.bodies('POST', DRAFTS)).toEqual([
            { kind: 'new_request', anchor: { kind: 'file', path: SMALL_FILE }, body: 'Split this class.', requireApproval: true },
            { kind: 'new_request', anchor: { kind: 'line', path: SMALL_FILE, range: { startLine: 10, endLine: 10 } }, body: 'Use a named constant.', requireApproval: true },
        ]);
        expect(api.bodies('POST', `${DRAFTS}/submit`)).toEqual([{ summary: 'First pass.' }]);
    });

    test('A draft can be edited and deleted before the submission', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await commentOnFile(page, 'Split this class.', 'Start a review');
        await commentOnFile(page, 'Delete me.', 'Add review comment');
        await expect(page.locator(FINISH)).toContainText('2');
        const [edited, deleted] = review.drafts;

        await chooseDraftAction(page, 'Split this class.', 'Edit');
        const editor = page.locator(`${FILE_THREADS} form[aria-label="Edit pending comment"] textarea`);
        await editor.fill('Split this class in two.');
        await commentBoxButton(page, 'Update comment').click();
        await expect(editor).toHaveCount(0);
        await waitForDraft(page, 'Split this class in two.');

        await chooseDraftAction(page, 'Delete me.', 'Delete');
        await expect(page.locator(FINISH)).toContainText('1');

        expect(api.callsTo('PATCH', '/api/v1/drafts/:draftId').map(({ params, body }) => [params.draftId, body])).toEqual([[edited?.id, { body: 'Split this class in two.', requireApproval: true }]]);
        expect(api.callsTo('DELETE', '/api/v1/drafts/:draftId').map(({ params }) => params.draftId)).toEqual([deleted?.id]);
        await expect(page.locator(SAVED_DRAFT)).toHaveCount(1);
    });

    test('The close dialog warns with the draft count', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await commentOnFile(page, 'Split this class.', 'Start a review');
        await commentOnFile(page, 'Name the constants.', 'Add review comment');

        await chooseReviewAction(page, 'Close review');

        await expect(page.locator('[data-testid="close-drafts-warning"]')).toContainText('You have 2 pending comments');
    });
});
