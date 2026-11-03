import type { Page } from '@playwright/test';
import { COMPOSER, commentBoxButton, dragFromPlus, focusedCommentBoxBelow, openFile, SUBMIT_FEEDBACK, waitForHighlighting } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE, SYMBOL_FILE } from './support/files';
import { apiError, redirect } from './support/mockApi';
import { clickRequestButton, DETAIL_THREAD, openRequest } from './support/requestScreens';
import { expect, test } from './support/test';

const PENDING_DRAFTS = '[data-testid="pending-drafts"] [data-testid="draft"][data-draft-id]';

/** A navigation with typed text can raise the beforeunload prompt of the unsaved-comment guard. */
function leaveWithoutAsking(page: Page): void {
    page.on('dialog', (dialog) => void dialog.accept());
}

function replyBox(page: Page) {
    return page.getByRole('textbox', { name: 'Reply', exact: true });
}

/** The "+" stays on the selected line (G1), so the form opens the box on other lines. */
async function commentOnLine(page: Page, line: number): Promise<void> {
    await page.getByRole('button', { name: 'Comment on lines', exact: true }).click();
    const form = page.getByTestId('comment-on-lines-form');
    await form.locator('input[name="start"]').fill(String(line));
    await form.locator('input[name="end"]').fill(String(line));
    await form.getByRole('button', { name: 'Comment', exact: true }).click();
}

function composerBelow(page: Page, line: number) {
    return page.locator(`[slot="annotation-${line}"] ${COMPOSER}`);
}

function storedDraftKeys(page: Page): Promise<string[]> {
    return page.evaluate(() => Object.keys(sessionStorage).filter((key) => key.startsWith('slopmower.draft:')));
}

test.describe('CommentDrafts', () => {
    test.beforeEach(({ page }) => leaveWithoutAsking(page));

    test('A reply typed on a request comes back after a reload, and a sent reply does not', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedGeneral('Wrap long lines.');
        await openRequest(page, request.id);
        await replyBox(page).fill('Wrapped at 120.');

        await page.reload();

        await expect(replyBox(page)).toHaveValue('Wrapped at 120.');
        await page.getByRole('button', { name: 'Reply', exact: true }).click();
        await expect(page.locator(DETAIL_THREAD)).toContainText('Wrapped at 120.');
        await expect(replyBox(page)).toHaveValue('');
        await page.reload();
        await expect(page.locator(DETAIL_THREAD)).toContainText('Wrapped at 120.');
        await expect(replyBox(page)).toHaveValue('');
    });

    test('The reply boxes of two requests keep their own text', async ({ page, api }) => {
        const review = new FakeReview(api);
        const first = review.seedGeneral('Wrap long lines.');
        const second = review.seedGeneral('Is the rounding intended?');
        await openRequest(page, first.id);
        await replyBox(page).fill('Wrapped at 120.');

        await openRequest(page, second.id);

        await expect(replyBox(page)).toHaveValue('');
        await replyBox(page).fill('Yes, half up.');
        await openRequest(page, first.id);
        await expect(replyBox(page)).toHaveValue('Wrapped at 120.');
        await openRequest(page, second.id);
        await expect(replyBox(page)).toHaveValue('Yes, half up.');
    });

    test('A line comment opens again on its lines with its text, also without the line hash, and other lines keep their own text', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await waitForHighlighting(page, 10);
        await dragFromPlus(page, 10, 10);
        await (await focusedCommentBoxBelow(page, 10)).fill('Use a named constant.');

        await openFile(page, review, SMALL_FILE);

        await expect(await focusedCommentBoxBelow(page, 10)).toHaveValue('Use a named constant.');
        await expect.poll(() => page.evaluate(() => location.hash)).toBe('#L10');
        await commentOnLine(page, 5);
        await expect(composerBelow(page, 5)).toHaveValue('');
        await composerBelow(page, 5).fill('Rename the variable.');
        await commentOnLine(page, 10);
        await expect(composerBelow(page, 10)).toHaveValue('Use a named constant.');
        await commentOnLine(page, 5);
        await expect(composerBelow(page, 5)).toHaveValue('Rename the variable.');
    });

    test('Discard in the unsaved-comment dialog removes the stored text, so the file opens without the comment box', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await waitForHighlighting(page, 10);
        await dragFromPlus(page, 10, 10);
        await (await focusedCommentBoxBelow(page, 10)).fill('Use a named constant.');

        await page.locator(`[data-testid="file-tree"] [data-path="${SYMBOL_FILE}"]`).click();
        await page.getByRole('alertdialog', { name: 'Discard your unsaved comment?' }).getByRole('button', { name: 'Discard', exact: true }).click();

        await expect(page.locator('[data-testid="file-header"]')).toContainText(SYMBOL_FILE);
        expect(await storedDraftKeys(page)).toEqual([]);
        await openFile(page, review, SMALL_FILE);
        await waitForHighlighting(page, 10);
        await expect(page.locator(COMPOSER)).toHaveCount(0);
    });

    test('A comment write answered with 401 keeps the text, and the file shows it again after sign-in', async ({ page, api }) => {
        const review = new FakeReview(api);
        let signedIn = true;
        api.on('POST', '/api/v1/reviews/:reviewId/drafts', () => (signedIn ? undefined : apiError(401, 'unauthorized', 'Sign in first.')));
        await openFile(page, review, SMALL_FILE);
        await waitForHighlighting(page, 10);
        await dragFromPlus(page, 10, 10);
        await (await focusedCommentBoxBelow(page, 10)).fill('Use a named constant.');
        signedIn = false;

        await commentBoxButton(page, 'Add comment').click();

        await expect(page.getByTestId('route-error').getByRole('heading')).toHaveText('Signed out');
        signedIn = true;
        await openFile(page, review, SMALL_FILE);
        await expect(await focusedCommentBoxBelow(page, 10)).toHaveValue('Use a named constant.');
    });

    test('An edited pending comment opens in edit mode after a reload, and Cancel discards the edit', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.seedDraft({ kind: 'new_request', anchor: null, body: 'Wrap long lines.' });
        await page.goto(`/reviews/${review.id}/requests`);
        const draft = page.locator(PENDING_DRAFTS);
        await draft.getByRole('button', { name: 'Pending comment actions' }).click();
        await page.getByRole('menuitem', { name: 'Edit', exact: true }).click();
        await draft.locator('textarea').fill('Wrap lines at 120 characters.');

        await page.reload();

        await expect(draft.locator('textarea')).toHaveValue('Wrap lines at 120 characters.');
        await draft.getByRole('button', { name: 'Cancel', exact: true }).click();
        await expect(draft.locator('textarea')).toHaveCount(0);
        await expect(draft).toContainText('Wrap long lines.');
        await page.reload();
        await expect(draft).toContainText('Wrap long lines.');
        await expect(draft.locator('textarea')).toHaveCount(0);
    });

    test('A note dialog shows its text again when it opens again after a reload, and Cancel removes it', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        review.agentClaims(request.id);
        review.agentMarksReady(request.id, 'The calculation is one line, so I left it.');
        await openRequest(page, request.id);
        await clickRequestButton(page, 'Reopen');
        await page.getByRole('dialog').locator('textarea').fill('Please extract it anyway.');

        await page.reload();
        await clickRequestButton(page, 'Reopen');

        await expect(page.getByRole('dialog').locator('textarea')).toHaveValue('Please extract it anyway.');
        await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click();
        await clickRequestButton(page, 'Reopen');
        await expect(page.getByRole('dialog').locator('textarea')).toHaveValue('');
    });

    test('The summary of Submit feedback comes back after a reload and is gone after the submit', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.seedDraft({ kind: 'new_request', anchor: null, body: 'Wrap long lines.' });
        await page.goto(`/reviews/${review.id}/requests`);
        await page.locator(SUBMIT_FEEDBACK).click();
        const summary = page.locator('form[data-testid="submit-feedback-form"] textarea[name="summary"]');
        await summary.fill('One general request.');

        await page.reload();
        await page.locator(SUBMIT_FEEDBACK).click();

        await expect(summary).toHaveValue('One general request.');
        await page.locator('form[data-testid="submit-feedback-form"]').getByRole('button', { name: 'Submit feedback', exact: true }).click();
        await expect(page.locator(SUBMIT_FEEDBACK)).toHaveCount(0);
        expect(api.bodies('POST', '/api/v1/reviews/:reviewId/drafts/submit')).toEqual([{ summary: 'One general request.', delivery: { workspace: null, pullRequest: null } }]);
        expect(await storedDraftKeys(page)).toEqual([]);
    });

    test('Sign-out removes the stored text', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedGeneral('Wrap long lines.');
        api.on('POST', '/logout', () => redirect('/login'));
        await openRequest(page, request.id);
        await replyBox(page).fill('Wrapped at 120.');
        expect(await storedDraftKeys(page)).toEqual([`slopmower.draft:request:${request.id}:reply`]);

        await page.locator('header form[action="/sign-out"] button').click();

        await expect(page).toHaveURL('/login');
        expect(await storedDraftKeys(page)).toEqual([]);
    });
});
