import type { Locator, Page } from '@playwright/test';
import { CODE_HOST, location, openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE, SYMBOL_FILE } from './support/files';
import type { MockApi } from './support/mockApi';
import { expect, test } from './support/test';

function treeItem(page: Page, path: string) {
    return page.locator(`[data-testid="file-tree"] [data-path="${path}"]`);
}

/** After the next click on `link`, its attribute `data-prevented` says whether the page prevented the browser's navigation. */
async function recordNextClick(link: Locator): Promise<void> {
    await link.evaluate((element) => {
        element.removeAttribute('data-prevented');
        element.addEventListener('click', (event) => setTimeout(() => element.setAttribute('data-prevented', String(event.defaultPrevented))), { once: true });
    });
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

    // The new tab of a Ctrl or Meta click is the browser's. Headless Chromium drops about 1 in 75 of them (F118 report).
    test('A file in the tree is a link: a Ctrl or Meta click is left to the browser, and a click opens it in place', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        const file = treeItem(page, SYMBOL_FILE);
        await expect(file).toHaveAttribute('href', review.fileUrl(SYMBOL_FILE));
        await file.hover();
        expect(await file.evaluate((link) => getComputedStyle(link).textDecorationLine)).toBe('none');
        expect(await file.evaluate((link) => getComputedStyle(link).color)).toBe(await treeItem(page, 'src/Billing').evaluate((item) => getComputedStyle(item).color));

        await recordNextClick(file);
        await file.click({ modifiers: ['ControlOrMeta'] });
        await expect(file).toHaveAttribute('data-prevented', 'false');
        expect(location(page)).toBe(review.fileUrl(SMALL_FILE));

        await recordNextClick(file);
        await file.click();

        await expect(file).toHaveAttribute('data-prevented', 'true');
        await expect(page.locator('[data-testid="file-header"]')).toContainText(SYMBOL_FILE);
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
