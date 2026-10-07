import type { Page } from '@playwright/test';
import { CODE_HOST, location, openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import type { MockApi } from './support/mockApi';
import { expect, test } from './support/test';

function treeItem(page: Page, path: string) {
    return page.locator(`[data-testid="file-tree"] [data-path="${path}"]`);
}

/** The label of a directory opens its directory view. The chevron only toggles it. */
function chevron(page: Page, path: string) {
    return treeItem(page, path).locator('.PRIVATE_TreeView-item-toggle').first();
}

/** The `dir` query of every V6 request that the page made. */
function listedDirectories(api: MockApi): (string | null)[] {
    return api.callsTo('GET', '/api/v1/reviews/:reviewId/tree').map(({ query }) => query.get('dir'));
}

test.describe('CodeView', () => {
    test('The tree expands directories on demand', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, 'README.md');
        await expect(treeItem(page, 'src')).toBeVisible();
        expect(listedDirectories(api)).toEqual(['']);

        await chevron(page, 'src').click();
        await expect(treeItem(page, 'src/Billing')).toBeVisible();
        await chevron(page, 'src/Billing').click();
        await expect(treeItem(page, SMALL_FILE)).toBeVisible();

        expect(listedDirectories(api)).toEqual(['', 'src', 'src/Billing']);
        expect(location(page)).toBe(review.fileUrl('README.md'));
        await treeItem(page, SMALL_FILE).click();
        await expect(page.locator('[data-testid="file-header"]')).toContainText(SMALL_FILE);
    });

    test('Line 1 text is readable from the shadow root', async ({ page, api }) => {
        const review = new FakeReview(api);

        await page.goto(review.fileUrl(SMALL_FILE));

        await expect(page.locator(`${CODE_HOST} [data-line="1"]`)).toHaveText('<?php');
    });

    test('The view has one line-number column', async ({ page, api }) => {
        const review = new FakeReview(api);

        await page.goto(review.fileUrl(SMALL_FILE));

        await expect(page.locator(`${CODE_HOST} [data-line="1"]`)).toBeAttached();
        await expect(page.locator(`${CODE_HOST} [data-gutter]`)).toHaveCount(1);
        await expect(page.locator(`${CODE_HOST} [data-column-number="1"]`)).toHaveCount(1);
    });
});
