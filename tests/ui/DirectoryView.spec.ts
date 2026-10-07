import type { Page } from '@playwright/test';
import { location, openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { codeViewFiles, SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

function table(page: Page) {
    return page.getByRole('table', { name: 'Folders and files' });
}

function row(page: Page, name: string) {
    return table(page).getByRole('row').filter({ has: page.getByRole('link', { name, exact: true }) });
}

function treeItem(page: Page, path: string) {
    return page.locator(`[data-testid="file-tree"] [data-path="${path}"]`);
}

test.describe('DirectoryView', () => {
    test('The Code tab shows the root as a table with sizes and viewed marks, and no parent row', async ({ page, api }) => {
        const review = new FakeReview(api);
        review.viewedPaths = ['README.md'];

        await page.goto(`/reviews/${review.id}`);

        await expect(table(page).getByRole('row').nth(1)).toContainText('public');
        await expect(row(page, 'src')).toBeVisible();
        await expect(row(page, 'README.md')).toContainText(`${'# Code view\n'.length} Bytes`);
        await expect(row(page, 'README.md').getByRole('img', { name: 'Viewed' })).toBeVisible();
        await expect(row(page, 'src').getByRole('img', { name: 'Viewed' })).toHaveCount(0);
        await expect(table(page).getByRole('link', { name: 'Parent directory' })).toHaveCount(0);
    });

    test('A directory row opens its directory with breadcrumbs and a parent row, and the parent row goes up', async ({ page, api }) => {
        const review = new FakeReview(api);
        await page.goto(`/reviews/${review.id}`);

        await row(page, 'src').getByRole('link').click();

        await expect.poll(() => location(page)).toBe(`/reviews/${review.id}/tree/src`);
        await expect(page.locator('[data-testid="file-header"] nav[aria-label="Breadcrumbs"]').first()).toHaveText(`${review.review.repositoryName}/src/`);
        await expect(row(page, 'Billing')).toBeVisible();
        await table(page).getByRole('link', { name: 'Parent directory' }).click();
        await expect.poll(() => location(page)).toBe(`/reviews/${review.id}`);
    });

    test('A file row opens the file, and a breadcrumb of the file opens its directory', async ({ page, api }) => {
        const review = new FakeReview(api);
        await page.goto(`/reviews/${review.id}/tree/src/Billing`);

        await row(page, 'InvoiceTotals.php').getByRole('link').click();
        await expect(page.locator('[data-testid="file-header"]')).toContainText(SMALL_FILE);
        await page.locator('[data-testid="file-header"] nav[aria-label="Breadcrumbs"]').first().getByRole('link', { name: 'Billing', exact: true }).click();

        await expect.poll(() => location(page)).toBe(`/reviews/${review.id}/tree/src/Billing`);
        await expect(row(page, 'InvoiceTotals.php')).toBeVisible();
    });

    test('A directory label in the tree opens its directory, and its chevron only toggles it', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, 'README.md');

        await treeItem(page, 'src').locator('.PRIVATE_TreeView-item-toggle').first().click();
        await expect(treeItem(page, 'src/Billing')).toBeVisible();
        expect(location(page)).toBe(review.fileUrl('README.md'));
        await treeItem(page, 'src/Billing').getByText('Billing', { exact: true }).click();

        await expect.poll(() => location(page)).toBe(`/reviews/${review.id}/tree/src/Billing`);
        await expect(treeItem(page, 'src/Billing')).toHaveAttribute('aria-current', 'true');
        await expect(treeItem(page, SMALL_FILE)).toBeVisible();
    });

    test('The README of a directory renders below the table, with its relative links and images rewritten', async ({ page, api }) => {
        const readme = '# Docs\n\nRead [the guide](guide.md), the [specs](specs/), and [the code](../src/Billing/InvoiceTotals.php#L7).\n\n![Logo](img/logo.png)\n';
        const review = new FakeReview(api, {
            files: { ...codeViewFiles(), 'docs/README.md': readme, 'docs/guide.md': '# Guide\n', 'docs/specs/a.md': '# A\n', 'docs/img/logo.png': null },
        });
        await page.goto(`/reviews/${review.id}/tree/docs`);
        const section = page.getByRole('region', { name: 'README.md' });

        await expect(section.getByRole('heading', { name: 'Docs' })).toBeVisible();
        await expect(section.getByRole('link', { name: 'specs' })).toHaveAttribute('href', `/reviews/${review.id}/tree/docs/specs`);
        await expect(section.getByRole('link', { name: 'the code' })).toHaveAttribute('href', `${review.fileUrl(SMALL_FILE)}#L7`);
        await expect(section.getByRole('img', { name: 'Logo' })).toHaveAttribute('src', `/api/v1/reviews/${review.id}/raw?path=docs%2Fimg%2Flogo.png`);
        await section.getByRole('link', { name: 'the guide' }).click();

        await expect(page.locator('[data-testid="file-header"]')).toContainText('docs/guide.md');
    });
});
