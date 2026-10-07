import { openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { apiError } from './support/mockApi';
import { expect, test } from './support/test';

test.describe('SymbolsMenu', () => {
    test('Symbols says why it is unavailable', async ({ page, api }) => {
        const review = new FakeReview(api);
        const symbols = page.locator('[data-testid="file-header"]').getByRole('button', { name: 'Symbols' });

        await openFile(page, review, 'README.md');
        await expect(symbols).toHaveAccessibleDescription('No symbols in this file');

        api.on('GET', '/api/v1/reviews/:reviewId/symbols', () => apiError(500, 'internal_error', 'The symbol index failed.'));
        await openFile(page, review, SMALL_FILE);
        await expect(symbols).toHaveAccessibleDescription('Symbols could not be loaded');
    });
});
