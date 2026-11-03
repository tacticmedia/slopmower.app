import type { Locator, Page } from '@playwright/test';
import { COMPOSER, FILE_THREADS, openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import type { MockApi } from './support/mockApi';
import { expect, test } from './support/test';

const BOX = `${FILE_THREADS} form[data-testid="comment-box"]`;

/** Opens the file comment box and returns its focused text field. */
async function openCommentBox(page: Page, api: MockApi): Promise<Locator> {
    await openFile(page, new FakeReview(api), SMALL_FILE);
    await page.getByRole('button', { name: 'Comment on file' }).click();
    const field = page.locator(`${FILE_THREADS} ${COMPOSER}`);
    await expect(field).toBeFocused();

    return field;
}

function tab(page: Page, name: string): Locator {
    return page.locator(BOX).getByRole('tab', { name });
}

test.describe('MarkdownEditor', () => {
    test('Bold wraps the selection in **', async ({ page, api }) => {
        const field = await openCommentBox(page, api);
        await field.fill('word');
        await field.selectText();

        await page.locator(BOX).getByRole('button', { name: 'Bold', exact: true }).click();

        await expect(field).toHaveValue('**word**');
    });

    test('Tab with a selection over 2 lines indents both, and Shift+Tab dedents them', async ({ page, api }) => {
        const field = await openCommentBox(page, api);
        await field.fill('one\ntwo');
        await field.selectText();

        await page.keyboard.press('Tab');
        await expect(field).toHaveValue('  one\n  two');
        await page.keyboard.press('Shift+Tab');
        await expect(field).toHaveValue('one\ntwo');
    });

    test('Tab without a selection moves the focus to the next control', async ({ page, api }) => {
        const field = await openCommentBox(page, api);
        await field.fill('one');

        await page.keyboard.press('Tab');

        await expect(page.locator(BOX).getByRole('checkbox', { name: 'Ask for a plan first' })).toBeFocused();
        await expect(field).toHaveValue('one');
    });

    test('Preview shows **x** in bold, and Write shows the text again', async ({ page, api }) => {
        const field = await openCommentBox(page, api);
        await field.fill('**x**');

        await tab(page, 'Preview').click();
        await expect(page.locator(`${BOX} [data-testid="markdown-body"] strong`)).toHaveText('x');
        await expect(field).toBeHidden();
        await tab(page, 'Write').click();

        await expect(field).toBeVisible();
        await expect(field).toHaveValue('**x**');
    });

    test('Mod+Shift+P toggles Write and Preview', async ({ page, api }) => {
        const field = await openCommentBox(page, api);

        await page.keyboard.press('ControlOrMeta+Shift+P');
        await expect(tab(page, 'Preview')).toHaveAttribute('aria-selected', 'true');
        await expect(field).toBeHidden();
        await page.keyboard.press('ControlOrMeta+Shift+P');

        await expect(tab(page, 'Write')).toHaveAttribute('aria-selected', 'true');
        await expect(field).toBeFocused();
    });

    test('Mod+K in the field inserts a link and opens no quick open', async ({ page, api }) => {
        const field = await openCommentBox(page, api);
        await field.fill('docs');
        await field.selectText();

        await page.keyboard.press('ControlOrMeta+k');

        await expect(field).toHaveValue('[docs](url)');
        await expect(page.getByRole('dialog', { name: 'Quick open' })).toHaveCount(0);
    });
});
