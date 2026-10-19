import { openFile, waitForHighlighting } from '../ui/support/codeView';
import { FakeReview } from '../ui/support/fakeReview';
import { SMALL_FILE } from '../ui/support/files';
import { json } from '../ui/support/mockApi';
import { test } from './support/test';

test.describe('ReviewTour', () => {
    test('A new reviewer meets the tour of the code browser, then the tour of a file, and finds it again in the Help menu', async ({ page, api, captureScreen }) => {
        api.me = { ...api.me, preferences: { completedTours: [] } };
        api.on('GET', '/api/v1/tokens', () => json('lists.schema.json#/$defs/TokenList', { items: [] }));
        const review = new FakeReview(api);

        await page.goto(`/reviews/${review.id}`);
        await captureScreen('Tour, code browser', page.getByRole('dialog', { name: 'File tree' }));

        await page.keyboard.press('Escape');
        await openFile(page, review, SMALL_FILE);
        await waitForHighlighting(page, 13);
        await captureScreen('Tour, file', page.getByRole('dialog', { name: 'Comment on code' }));

        await page.keyboard.press('Escape');
        await page.getByRole('button', { name: 'Help' }).click();
        await captureScreen('Help menu on a review', page.getByRole('menu'));
    });
});
