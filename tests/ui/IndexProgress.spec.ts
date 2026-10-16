import { now } from './support/builders';
import { FakeReview } from './support/fakeReview';
import { expect, test } from './support/test';

const PROGRESS = '[data-testid="index-progress"]';

test.describe('IndexProgress', () => {
    test('The progress bar shows while the index runs, and a ready index shows nothing', async ({ page, api, live }) => {
        const review = new FakeReview(api, { review: { index: { state: 'pending', filesTotal: null, filesDone: 0, symbolsTotal: 0, startedAt: null, finishedAt: null, error: null } } });
        await page.goto(`/reviews/${review.id}`);
        await expect(page.locator(`${PROGRESS}[data-state="pending"]`)).toBeVisible();
        await expect(page.locator('[data-live="connected"]')).toBeAttached();
        await expect(page.locator(`${PROGRESS} [role="progressbar"][aria-valuenow="0"]`)).toBeAttached();

        review.review = { ...review.review, index: { state: 'ready', filesTotal: 3, filesDone: 3, symbolsTotal: 4, startedAt: now(-1), finishedAt: now(), error: null } };
        await live.push({ type: 'index', reviewId: review.id });

        await expect(page.locator(PROGRESS)).toHaveCount(0);
    });
});
