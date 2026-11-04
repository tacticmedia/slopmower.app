import type { Locator, Page } from '@playwright/test';
import { commentBoxButton, dragFromPlus, focusedCommentBoxBelow, openFile, SAVED_DRAFT, selectedLines, waitForHighlighting } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

const EDITOR = 'form[aria-label="Edit pending comment"]';

async function editDraft(page: Page, body: string): Promise<Locator> {
    const draft = page.locator(SAVED_DRAFT).filter({ hasText: body });
    await draft.getByRole('button', { name: 'Pending comment actions' }).click();
    await page.getByRole('menuitem', { name: 'Edit', exact: true }).click();
    await expect(draft.locator(EDITOR)).toBeVisible();

    return draft;
}

function headerBox(draft: Locator): Promise<{ linkX: number; headerHeight: number }> {
    return draft.locator('header').evaluate((header) => {
        const link = [...header.querySelectorAll('button')].find((button) => ['Update selection', 'Done'].includes(button.textContent ?? ''));

        return { linkX: link?.getBoundingClientRect().x ?? NaN, headerHeight: header.getBoundingClientRect().height };
    });
}

test.describe('CommentForm', () => {
    test('The title and description fields show placeholders, and the request options sit on a row above the buttons', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await waitForHighlighting(page, 10);

        await dragFromPlus(page, 10, 10);
        await focusedCommentBoxBelow(page, 10);
        const form = page.locator('form[data-testid="comment-box"]');

        await expect(form.getByRole('textbox', { name: 'Title', exact: true })).toHaveAttribute('placeholder', 'Title (optional)');
        await expect(form.getByRole('textbox', { name: 'Description', exact: true })).toHaveAttribute('placeholder', 'Description');
        const options = await form.getByRole('combobox', { name: 'Type' }).boundingBox();
        const cancel = await commentBoxButton(page, 'Cancel').boundingBox();
        expect(null !== options && null !== cancel && options.y + options.height <= cancel.y).toBe(true);
    });

    test('Edit highlights the lines of a pending comment, and Cancel removes the highlight', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.seedDraft({ kind: 'new_request', anchor: { kind: 'range', path: SMALL_FILE, range: { startLine: 9, endLine: 12 } }, body: 'Extract the tax calculation.' });
        await openFile(page, review, SMALL_FILE);
        await waitForHighlighting(page, 12);

        const draft = await editDraft(page, 'Extract the tax calculation.');
        await expect.poll(() => selectedLines(page)).toEqual(['9', '10', '11', '12']);

        await draft.getByRole('button', { name: 'Cancel', exact: true }).click();
        await expect.poll(() => selectedLines(page)).toEqual([]);
    });

    test('Update selection and Done move the lines without a layout shift, and Update comment sends the new anchor', async ({ page, api }) => {
        const review = new FakeReview(api);
        const seeded = review.seedDraft({ kind: 'new_request', anchor: { kind: 'range', path: SMALL_FILE, range: { startLine: 9, endLine: 12 } }, body: 'Extract the tax calculation.' });
        await openFile(page, review, SMALL_FILE);
        await waitForHighlighting(page, 12);
        const draft = await editDraft(page, 'Extract the tax calculation.');
        const before = await headerBox(draft);

        await draft.getByRole('button', { name: 'Update selection', exact: true }).click();
        expect(await headerBox(draft)).toEqual(before);
        await draft.getByRole('textbox', { name: 'Start line' }).fill('5');
        await draft.getByRole('textbox', { name: 'End line' }).fill('6');
        await draft.getByRole('button', { name: 'Done', exact: true }).click();

        expect(await headerBox(draft)).toEqual(before);
        await expect(draft.getByTestId('draft-lines')).toHaveText('Lines 5 to 6');
        await expect.poll(() => selectedLines(page)).toEqual(['5', '6']);

        await draft.getByRole('button', { name: 'Update comment', exact: true }).click();
        await expect(page.locator(`[slot="annotation-6"] ${SAVED_DRAFT}`)).toContainText('Extract the tax calculation.');
        expect(api.callsTo('PATCH', '/api/v1/drafts/:draftId').map(({ params, body }) => [params.draftId, body])).toEqual([
            [seeded.id, { body: 'Extract the tax calculation.', requireApproval: false, type: 'change', anchor: { kind: 'range', path: SMALL_FILE, range: { startLine: 5, endLine: 6 } } }],
        ]);
    });

    test('Without the edit form, Update selection highlights the lines, and Done saves the new lines with the stored text', async ({ page, api }) => {
        const review = new FakeReview(api);
        const seeded = review.seedDraft({ kind: 'new_request', anchor: { kind: 'range', path: SMALL_FILE, range: { startLine: 9, endLine: 12 } }, title: 'Tax', body: 'Extract the tax calculation.', group: 'Totals' });
        await openFile(page, review, SMALL_FILE);
        await waitForHighlighting(page, 12);
        const draft = page.locator(SAVED_DRAFT).filter({ hasText: 'Extract the tax calculation.' });

        await draft.getByRole('button', { name: 'Update selection', exact: true }).click();
        await expect.poll(() => selectedLines(page)).toEqual(['9', '10', '11', '12']);
        await draft.getByRole('textbox', { name: 'Start line' }).fill('5');
        await draft.getByRole('textbox', { name: 'End line' }).fill('6');
        await draft.getByRole('button', { name: 'Done', exact: true }).click();

        await expect(page.locator(`[slot="annotation-6"] ${SAVED_DRAFT}`)).toContainText('Extract the tax calculation.');
        await expect(page.locator(`[slot="annotation-6"] ${SAVED_DRAFT}`).getByTestId('draft-lines')).toHaveText('Lines 5 to 6');
        await expect.poll(() => selectedLines(page)).toEqual([]);
        expect(api.callsTo('PATCH', '/api/v1/drafts/:draftId').map(({ params, body }) => [params.draftId, body])).toEqual([
            [seeded.id, { title: 'Tax', body: 'Extract the tax calculation.', group: 'Totals', anchor: { kind: 'range', path: SMALL_FILE, range: { startLine: 5, endLine: 6 } } }],
        ]);
    });

    test('A line past the end of the file shows an error and keeps the inputs', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.seedDraft({ kind: 'new_request', anchor: { kind: 'line', path: SMALL_FILE, range: { startLine: 10, endLine: 10 } }, body: 'Use a named constant.' });
        await openFile(page, review, SMALL_FILE);
        await waitForHighlighting(page, 10);
        const draft = await editDraft(page, 'Use a named constant.');

        await draft.getByRole('button', { name: 'Update selection', exact: true }).click();
        await draft.getByRole('textbox', { name: 'End line' }).fill('99');
        await draft.getByRole('textbox', { name: 'End line' }).press('Enter');

        await expect(draft.getByTestId('comment-error')).toHaveText(/^The file has \d+ lines\.$/);
        await expect(draft.getByRole('button', { name: 'Done', exact: true })).toBeVisible();
        await expect.poll(() => selectedLines(page)).toEqual(['10']);
    });
});
