import type { Page } from '@playwright/test';
import { FakeReview } from './support/fakeReview';
import { apiError } from './support/mockApi';
import { DETAIL, REQUEST_ROWS } from './support/requestScreens';
import { reportToSentry } from './support/sentry';
import { expect, test } from './support/test';

const TITLE = 'Split the class';

/** Fails the request screen chunk in the first `failingDocuments` document loads. Returns the count of document loads. */
async function failRequestChunk(page: Page, failingDocuments: number): Promise<() => number> {
    let documents = 0;
    page.on('request', (request) => {
        if (request.isNavigationRequest() && request.frame() === page.mainFrame()) {
            documents++;
        }
    });
    await page.route(/\/RequestDetail-[\w-]+\.js$/, (route) => (documents <= failingDocuments ? route.abort('failed') : route.fallback()));

    return () => documents;
}

test.describe('ChunkReload', () => {
    test('A click on a request whose screen chunk fails reloads the page once, shows the request, and sends no event', async ({ page, api }) => {
        const events = await reportToSentry(page, 'event');
        const review = new FakeReview(api);
        const request = review.seedGeneral('Split the class into two.', false, TITLE);
        const documents = await failRequestChunk(page, 1);
        await page.goto(`/reviews/${review.id}/requests`);

        await page.locator(REQUEST_ROWS).getByRole('link', { name: TITLE, exact: true }).click();

        await expect(page.locator(`${DETAIL}[data-request-id="${request.id}"]`)).toBeVisible();
        await expect(page).toHaveURL(new RegExp(`/requests/${request.id}$`));
        expect(documents()).toBe(2);
        api.on('GET', '/api/v1/reviews/:reviewId/requests', () => apiError(500, 'internal_error', 'The list failed.'));
        await page.goto(`/reviews/${review.id}/requests`);
        await expect(page.getByTestId('route-error')).toContainText('The list failed.');
        await expect.poll(() => events.length).toBe(1);
        expect(JSON.parse(events[0] ?? '').exception.values[0].value).toBe('The list failed.');
    });

    test('A screen chunk that fails also after the reload shows "Loading failed" and sends one event, and Try again reloads the page', async ({ page, api }) => {
        const events = await reportToSentry(page, 'event');
        const review = new FakeReview(api);
        const request = review.seedGeneral('Split the class into two.', false, TITLE);
        const documents = await failRequestChunk(page, 2);

        await page.goto(`/requests/${request.id}`);

        const routeError = page.getByTestId('route-error');
        await expect(routeError.getByRole('heading')).toHaveText('Loading failed');
        await expect(routeError).toContainText('Part of Slopmower did not load. Check your connection and try again.');
        expect(documents()).toBe(2);
        await expect.poll(() => events.length).toBe(1);
        expect(JSON.parse(events[0] ?? '').exception.values[0].value).toContain('RequestDetail');
        await page.getByRole('button', { name: 'Try again' }).click();
        await expect(page.locator(`${DETAIL}[data-request-id="${request.id}"]`)).toBeVisible();
        expect(documents()).toBe(3);
        expect(events).toHaveLength(1);
    });
});
