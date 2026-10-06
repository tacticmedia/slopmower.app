import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { expect, test } from './support/test';

const PENDING_DRAFTS = '[data-testid="pending-drafts"] [data-testid="draft"][data-draft-id]';
const FINISH = '[data-testid="finish-review"]';

test.describe('PendingDrafts', () => {
    test('The Requests tab lists every pending draft with its label, and a line draft links to its lines', async ({ page, api }) => {
        const review = new FakeReview(api);
        const request = review.seedLines(SMALL_FILE, 3, 3, 'Is strict_types needed here?');
        review.seedDraft({ kind: 'new_request', anchor: { kind: 'range', path: SMALL_FILE, range: { startLine: 9, endLine: 12 } }, body: 'Extract the tax calculation.' });
        review.seedDraft({ kind: 'reply', requestId: request.id, body: 'Yes, keep it.' });
        review.seedDraft({ kind: 'new_request', anchor: null, body: 'Wrap long lines.' });

        await page.goto(`/reviews/${review.id}/requests`);

        const drafts = page.locator(PENDING_DRAFTS);
        await expect(drafts).toHaveCount(3);
        await expect(drafts.nth(0)).toContainText(`${SMALL_FILE}:L9-L12`);
        await expect(drafts.nth(1)).toContainText('Reply to Is strict_types needed here?');
        await expect(drafts.nth(2)).toContainText('General');
        await expect(page.locator(FINISH)).toContainText('3');

        await drafts.nth(0).getByRole('link', { name: `${SMALL_FILE}:L9-L12`, exact: true }).click();

        await expect(page).toHaveURL(review.fileUrl(SMALL_FILE, '#L9-L12'));
        await expect(page.locator('[slot="annotation-12"] [data-testid="draft"]')).toContainText('Extract the tax calculation.');
    });
});
