import type { Page } from '@playwright/test';
import { addSingleComment, CODE_HOST, COMPOSER, commentBoxButton, FILE_THREADS, lineNumber, openFile, waitForHighlighting, waitForSavedThread } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { BINARY_FILE, SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

const REQUESTS = '/api/v1/reviews/:reviewId/requests';

async function commentOnFile(page: Page, body: string): Promise<void> {
    await page.getByRole('button', { name: 'Comment on file' }).click();
    const textarea = page.locator(`${FILE_THREADS} ${COMPOSER}`);
    await expect(textarea).toBeVisible();
    await addSingleComment(page, textarea, body, FILE_THREADS);
}

test.describe('FileComment', () => {
    test('The file comment button creates a file anchor, also on a binary file', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await commentOnFile(page, 'Split this class.');

        await openFile(page, review, BINARY_FILE);
        await expect(page.locator('[data-testid="file-placeholder"]')).toBeVisible();
        await commentOnFile(page, 'Use an SVG.');

        expect(api.bodies('POST', REQUESTS)).toEqual([
            { anchor: { kind: 'file', path: SMALL_FILE }, body: 'Split this class.' },
            { anchor: { kind: 'file', path: BINARY_FILE }, body: 'Use an SVG.' },
        ]);
        await page.reload();
        await waitForSavedThread(page, FILE_THREADS, 'Use an SVG.');
    });

    test('A closed review shows no comment commands and no "+"', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.seedLines(SMALL_FILE, 3, 3, 'Seeded before the close.');
        review.close();

        await openFile(page, review, SMALL_FILE);
        await waitForSavedThread(page, '[slot="annotation-3"]', 'Seeded before the close.');
        await waitForHighlighting(page, 5);
        await lineNumber(page, 5).hover();

        await expect(page.getByRole('button', { name: /^Comment on (file|lines)$/ })).toHaveCount(0);
        await expect(page.locator('textarea[aria-label="Reply"]')).toHaveCount(0);
        await expect(page.locator(`${CODE_HOST} [data-utility-button]`)).toHaveCount(0);
    });

    test('Escape cancels the comment box, and Ctrl+Enter sends it', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        const composer = page.locator(`${FILE_THREADS} ${COMPOSER}`);
        await page.getByRole('button', { name: 'Comment on file' }).click();
        await composer.fill('Discard this.');

        await composer.press('Escape');
        await expect(composer).toHaveCount(0);
        await page.getByRole('button', { name: 'Comment on file' }).click();
        await composer.fill('Split this class.');
        await composer.press('ControlOrMeta+Enter');

        await waitForSavedThread(page, FILE_THREADS, 'Split this class.');
        expect(api.bodies('POST', REQUESTS)).toEqual([{ anchor: { kind: 'file', path: SMALL_FILE }, body: 'Split this class.' }]);
    });

    test('A failed comment opens its box again with the text and the error', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await page.getByRole('button', { name: 'Comment on file' }).click();
        const composer = page.locator(`${FILE_THREADS} ${COMPOSER}`);
        await expect(composer).toBeVisible();
        review.close();

        await composer.fill('Too late.');
        await commentBoxButton(page, 'Add single comment').click();

        await expect(page.locator(`${FILE_THREADS} [data-testid="comment-error"]`)).toBeVisible();
        await expect(composer).toHaveValue('Too late.');
        await expect(page.locator('[data-testid="thread"]')).toHaveCount(0);
        expect(api.bodies('POST', REQUESTS)).toEqual([{ anchor: { kind: 'file', path: SMALL_FILE }, body: 'Too late.' }]);
    });
});
