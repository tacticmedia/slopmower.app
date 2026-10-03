import type { Page } from '@playwright/test';
import type { CommentInput } from '../../assets/api/types/inputs';
import { dragFromPlus, focusedCommentBoxBelow, openFile, waitForHighlighting } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { apiError, type MockApi } from './support/mockApi';
import { expect, test } from './support/test';

const THREAD = '[slot="annotation-3"] [data-testid="thread"]';
const REPLY_BOX = `${THREAD} textarea[aria-label="Reply"]`;
const COMMENTS = '/api/v1/requests/:requestId/comments';
/** The `maxLength` of Markdown in docs/specs/schemas/common.schema.json. */
const MARKDOWN_MAX_LENGTH = 65536;

async function openSeededThread(page: Page, api: MockApi, body: string): Promise<FakeReview> {
    const review = new FakeReview(api);
    review.seedLines(SMALL_FILE, 3, 3, body);
    await openFile(page, review, SMALL_FILE);
    await expect(page.locator(REPLY_BOX)).toBeVisible();

    return review;
}

test.describe('ReplyAndMarkdown', () => {
    test('A reply appears in the thread', async ({ page, api }) => {
        await openSeededThread(page, api, 'Please rename this.');

        await page.locator(REPLY_BOX).fill('Renamed in my head already.');
        await page.getByRole('button', { name: 'Reply', exact: true }).click();

        await expect(page.locator(`${THREAD} [data-testid="thread-comment"][aria-busy="false"]`)).toHaveCount(2);
        await expect(page.locator(THREAD)).toContainText('Renamed in my head already.');
        await expect(page.locator(REPLY_BOX), 'A saved reply clears the box.').toHaveValue('');
        expect(api.bodies('POST', COMMENTS)).toEqual([{ body: 'Renamed in my head already.' }]);

        await page.reload();
        await expect(page.locator(REPLY_BOX)).toBeVisible();
        await expect(page.locator(THREAD)).toContainText('Renamed in my head already.');
    });

    test('An unsent reply stays when a comment box opens above its line', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.seedLines(SMALL_FILE, 10, 10, 'Seeded on line 10.');
        await openFile(page, review, SMALL_FILE);
        const replyBox = page.locator('[slot="annotation-10"] textarea[aria-label="Reply"]');
        await expect(replyBox).toBeVisible();
        await waitForHighlighting(page, 5);
        await replyBox.fill('Half a reply');

        await dragFromPlus(page, 5, 5);
        await focusedCommentBoxBelow(page, 5);

        await expect(replyBox).toHaveValue('Half a reply');
    });

    test('A failed reply keeps its text and shows the error', async ({ page, api }) => {
        await openSeededThread(page, api, 'Please rename this.');
        api.on('POST', COMMENTS, ({ body }) =>
            (body as CommentInput).body.length > MARKDOWN_MAX_LENGTH
                ? apiError(422, 'invalid_input', 'The comment is too long.', { violations: [{ field: 'body', message: `This value is too long. It should have ${MARKDOWN_MAX_LENGTH} characters or less.` }] })
                : undefined,
        );
        // Typing that many keys takes too long, so the field gets the value.
        await page.locator(REPLY_BOX).evaluate((field, length) => {
            Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set?.call(field, 'x'.repeat(length));
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }, MARKDOWN_MAX_LENGTH + 1);

        await page.getByRole('button', { name: 'Reply', exact: true }).click();

        await expect(page.locator(`${THREAD} [data-testid="comment-error"]`)).toBeVisible();
        expect(await page.locator(REPLY_BOX).evaluate((field) => (field as HTMLTextAreaElement).value.length)).toBe(MARKDOWN_MAX_LENGTH + 1);
        await expect(page.locator(`${THREAD} [data-testid="thread-comment"]`)).toHaveCount(1);
    });

    test('**bold** renders as strong and a script tag shows as text', async ({ page, api }) => {
        await openSeededThread(page, api, 'Make this **bold**.\n\n<script>window.injected = true</script>');
        const markdown = page.locator(`${THREAD} [data-testid="markdown-body"]`);

        await expect(markdown.locator('strong')).toHaveText('bold');
        await expect(markdown).toContainText('<script>window.injected = true</script>');
        await expect(markdown.locator('script')).toHaveCount(0);
        expect(await page.evaluate(() => (window as Window & { injected?: boolean }).injected ?? null)).toBeNull();
    });

    test('"Quote reply" puts the comment as a quote into the reply box', async ({ page, api }) => {
        await openSeededThread(page, api, 'First line.\nSecond line.');

        await page.locator(`${THREAD} [data-testid="thread-comment"] button[aria-haspopup="true"]`).click();
        await page.getByRole('menuitem', { name: 'Quote reply' }).click();

        await expect(page.locator(REPLY_BOX)).toBeFocused();
        await expect(page.locator(REPLY_BOX)).toHaveValue('> First line.\n> Second line.\n\n');
    });
});
