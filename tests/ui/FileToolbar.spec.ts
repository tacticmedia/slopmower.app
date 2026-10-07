import { openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE, SMALL_FILE_TEXT } from './support/files';
import { expect, test } from './support/test';

const TOOLBAR = '[data-testid="file-toolbar"]';

test.describe('FileToolbar', () => {
    test('The toolbar shows the line count and the size, and Raw and Download read the file through V8', async ({ page, api }) => {
        const review = new FakeReview(api);
        const raw = `/api/v1/reviews/${review.id}/raw?path=${encodeURIComponent(SMALL_FILE)}`;

        await openFile(page, review, SMALL_FILE);

        // Lines 2, 4, and 6 are blank.
        await expect(page.locator(TOOLBAR)).toContainText(`13 lines (10 loc) · ${SMALL_FILE_TEXT.length} Bytes`);
        await expect(page.locator(TOOLBAR).getByRole('link', { name: 'Raw', exact: true })).toHaveAttribute('href', raw);
        await expect(page.locator(TOOLBAR).getByRole('link', { name: 'Raw', exact: true })).toHaveAttribute('target', '_blank');
        const download = page.locator(TOOLBAR).getByRole('link', { name: 'Download raw file' });
        await expect(download).toHaveAttribute('href', raw);
        await expect(download).toHaveAttribute('download', 'InvoiceTotals.php');
    });

    test('Copy raw file puts the text of the file on the clipboard', async ({ page, api }) => {
        await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
        await openFile(page, new FakeReview(api), SMALL_FILE);

        await page.locator(TOOLBAR).getByRole('button', { name: 'Copy raw file' }).click();

        await expect(page.getByRole('status').filter({ hasText: 'Copied.' })).toHaveCount(1);
        expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(SMALL_FILE_TEXT);
    });

    test('Copy path follows the breadcrumbs of the file', async ({ page, api }) => {
        await openFile(page, new FakeReview(api), SMALL_FILE);
        const breadcrumbs = page.locator('[data-testid="file-header"] nav[aria-label="Breadcrumbs"]').first();

        const next = breadcrumbs.locator('xpath=following-sibling::*[1]');

        await expect(next.getByRole('button', { name: 'Copy path' })).toBeVisible();
    });
});
