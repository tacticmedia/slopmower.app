import type { Page } from '@playwright/test';
import { commentBoxButton, dragFromPlus, focusedCommentBoxBelow, lineNumber, location, openFile, waitForHighlighting, waitForSavedDraft } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { openRequest } from './support/requestScreens';
import { SMALL_FILE, SYMBOL_FILE } from './support/files';
import { expect, test } from './support/test';

const DIALOG = 'Discard your unsaved comment?';

function treeItem(page: Page, path: string) {
    return page.locator(`[data-testid="file-tree"] [data-path="${path}"]`);
}

function discardDialog(page: Page) {
    return page.getByRole('alertdialog', { name: DIALOG });
}

async function openCommentBoxOnLine10(page: Page, review: FakeReview) {
    await openFile(page, review, SMALL_FILE);
    await waitForHighlighting(page, 10);
    await dragFromPlus(page, 10, 10);

    return focusedCommentBoxBelow(page, 10);
}

async function expectFileOpen(page: Page, path: string): Promise<void> {
    await expect(page.locator('[data-testid="file-header"]')).toContainText(path);
}

test.describe('UnsavedComment', () => {
    test('Typed text and a click on another file ask first: Keep editing keeps the file and the text, and Discard opens the other file', async ({ page, api }) => {
        const review = new FakeReview(api);
        const textarea = await openCommentBoxOnLine10(page, review);
        await textarea.fill('Use a named constant.');

        await treeItem(page, SYMBOL_FILE).click();
        await discardDialog(page).getByRole('button', { name: 'Keep editing', exact: true }).click();

        await expect(discardDialog(page)).toHaveCount(0);
        expect(location(page)).toContain(review.fileUrl(SMALL_FILE));
        await expect(textarea).toHaveValue('Use a named constant.');

        await treeItem(page, SYMBOL_FILE).click();
        await discardDialog(page).getByRole('button', { name: 'Discard', exact: true }).click();

        await expectFileOpen(page, SYMBOL_FILE);
    });

    test('An empty comment box does not ask, and a line click with typed text does not ask', async ({ page, api }) => {
        const review = new FakeReview(api);
        const textarea = await openCommentBoxOnLine10(page, review);
        await textarea.fill('Use a named constant.');

        await lineNumber(page, 3).click();

        await expect(page).toHaveURL(/#L3$/);
        await expect(discardDialog(page)).toHaveCount(0);

        await textarea.fill('');
        await treeItem(page, SYMBOL_FILE).click();

        await expectFileOpen(page, SYMBOL_FILE);
        await expect(discardDialog(page)).toHaveCount(0);
    });

    test('A pending comment with changed text in its edit box asks first', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.seedDraft({ kind: 'new_request', anchor: { kind: 'range', path: SMALL_FILE, range: { startLine: 9, endLine: 12 } }, body: 'Extract the tax calculation.' });
        await openFile(page, review, SMALL_FILE);
        const draft = page.locator('[slot="annotation-12"] [data-testid="draft"]');
        await draft.getByRole('button', { name: 'Pending comment actions' }).click();
        await page.getByRole('menuitem', { name: 'Edit', exact: true }).click();

        await draft.locator('textarea').fill('Extract the tax calculation into a method.');
        await treeItem(page, SYMBOL_FILE).click();

        await expect(discardDialog(page)).toBeVisible();
    });

    test('A pending comment with only a changed group in its edit box asks first', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.seedDraft({ kind: 'new_request', anchor: { kind: 'range', path: SMALL_FILE, range: { startLine: 9, endLine: 12 } }, body: 'Extract the tax calculation.' });
        await openFile(page, review, SMALL_FILE);
        const draft = page.locator('[slot="annotation-12"] [data-testid="draft"]');
        await draft.getByRole('button', { name: 'Pending comment actions' }).click();
        await page.getByRole('menuitem', { name: 'Edit', exact: true }).click();

        await draft.getByRole('combobox', { name: 'Group' }).fill('Tax');
        await treeItem(page, SYMBOL_FILE).click();

        await expect(discardDialog(page)).toBeVisible();
    });

    test('A comment that was added does not ask', async ({ page, api }) => {
        const review = new FakeReview(api);
        const textarea = await openCommentBoxOnLine10(page, review);
        await textarea.fill('Use a named constant.');
        await commentBoxButton(page, 'Add comment').click();
        await waitForSavedDraft(page, '[slot="annotation-10"]', 'Use a named constant.');

        await treeItem(page, SYMBOL_FILE).click();

        await expectFileOpen(page, SYMBOL_FILE);
        await expect(discardDialog(page)).toHaveCount(0);
    });

    test('The reply box of the request page asks first before a link to another page', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 9, 12, 'Extract the tax calculation.');
        await openRequest(page, request.id);
        await page.getByRole('textbox', { name: 'Reply', exact: true }).fill('Keep the old method for one release.');

        await page.getByRole('link', { name: 'Requests of this review', exact: true }).click();

        await expect(discardDialog(page)).toBeVisible();
    });

    test('Closing the tab with typed text raises the beforeunload prompt', async ({ page, api }) => {
        const review = new FakeReview(api);
        const textarea = await openCommentBoxOnLine10(page, review);
        await textarea.pressSequentially('Use a named constant.');
        const dialog = page.waitForEvent('dialog');

        await page.close({ runBeforeUnload: true });

        const prompt = await dialog;
        expect(prompt.type()).toBe('beforeunload');
        await prompt.dismiss();
    });
});
