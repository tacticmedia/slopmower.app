import type { Page } from '@playwright/test';
import { FakeRepositories } from './support/fakeRepositories';
import { type MockApi, redirect } from './support/mockApi';
import { expect, test } from './support/test';

const CURRENT_USER = '[data-testid="current-user"]';

/** `/` opens the repositories screen. */
async function openShell(page: Page, api: MockApi): Promise<void> {
    new FakeRepositories(api);
    await page.goto('/');
    await expect(page.locator(CURRENT_USER)).toHaveText('jan');
}

async function markWindow(page: Page): Promise<void> {
    await page.evaluate(() => Object.assign(window, { shellMarker: 'kept' }));
}

function windowMarker(page: Page): Promise<unknown> {
    return page.evaluate(() => (window as Window & { shellMarker?: string }).shellMarker);
}

test.describe('Shell', () => {
    test('The header shows the login of the signed-in user', async ({ page, api }) => {
        await openShell(page, api);

        await expect(page.locator(`header ${CURRENT_USER}`)).toHaveText('jan');
    });

    test('Client-side navigation keeps a window marker', async ({ page, api }) => {
        await openShell(page, api);
        await markWindow(page);

        await page.locator('nav[aria-label="Main"] a[href="/settings"]').click();

        await expect(page).toHaveURL('/settings');
        expect(await windowMarker(page)).toBe('kept');
    });

    test('Client-side navigation to an unknown path shows the error boundary', async ({ page, api }) => {
        await openShell(page, api);
        await markWindow(page);

        await page.evaluate(() => {
            history.pushState(null, '', '/no-such-page');
            dispatchEvent(new PopStateEvent('popstate'));
        });

        await expect(page.locator('[data-testid="route-error"] h1')).toHaveText('Page not found');
        expect(await windowMarker(page)).toBe('kept');
    });

    test('Sign out posts the CSRF token to /logout and lands on /login', async ({ page, api }) => {
        api.on('POST', '/logout', () => redirect('/login'));
        await openShell(page, api);

        await page.locator('header form[action="/sign-out"] button').click();

        await expect(page).toHaveURL('/login');
        const logouts = api.callsTo('POST', '/logout');
        expect(logouts).toHaveLength(1);
        expect(logouts[0]?.body).toEqual({ _csrf_token: logouts[0]?.headers['csrf-token'] });
    });
});
