import type { Page } from '@playwright/test';
import { openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { apiError, type MockApi } from './support/mockApi';
import { expect, test } from './support/test';

/** The longest delay of useSessionKeepalive. */
const MAX_DELAY_MS = 8 * 60_000;

const ME = '/api/v1/me';

const UPDATES = '/api/v1/reviews/:reviewId/updates';

function signedOutDialog(page: Page) {
    return page.getByRole('dialog', { name: 'You are signed out' });
}

/** Waits for the first live read, so a later catch-up reads updates before it revalidates. */
async function openLiveFile(page: Page, api: MockApi): Promise<void> {
    await openFile(page, new FakeReview(api), SMALL_FILE);
    await expect(page.locator('[data-live="connected"]')).toBeAttached();
    await expect.poll(() => api.callsTo('GET', UPDATES).length).toBeGreaterThan(0);
}

/** The reads that the page sends by itself answer 401, as for an expired session. */
function signOutElsewhere(api: MockApi): void {
    for (const path of [ME, UPDATES]) {
        api.on('GET', path, () => apiError(401, 'unauthorized', 'Sign in first.'));
    }
}

test.describe('SessionKeepalive', () => {
    test('An open page sends A1 again within 8 minutes, and a 401 opens "You are signed out", which Dismiss closes', async ({ page, api }) => {
        await page.clock.install();
        await openLiveFile(page, api);
        const loaded = api.callsTo('GET', ME).length;

        await page.clock.runFor(MAX_DELAY_MS);

        await expect.poll(() => api.callsTo('GET', ME).length).toBe(loaded + 1);
        await expect(signedOutDialog(page)).toHaveCount(0);
        signOutElsewhere(api);
        await page.clock.runFor(MAX_DELAY_MS);
        await expect(signedOutDialog(page)).toBeVisible();
        await signedOutDialog(page).getByRole('button', { name: 'Dismiss', exact: true }).click();
        await expect(signedOutDialog(page)).toHaveCount(0);
        await page.clock.runFor(MAX_DELAY_MS);
        expect(api.callsTo('GET', ME), 'A1 stops after the 401.').toHaveLength(loaded + 2);
    });

    test('A tab that becomes visible sends A1 at once, and Sign in opens the sign-in page', async ({ page, api }) => {
        await openLiveFile(page, api);
        signOutElsewhere(api);

        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));

        await expect(signedOutDialog(page)).toBeVisible();
        await signedOutDialog(page).getByRole('button', { name: 'Sign in', exact: true }).click();
        await expect(page).toHaveURL('/login');
    });
});
