import { localRepository } from './support/builders';
import { FakeRepositories } from './support/fakeRepositories';
import { apiError } from './support/mockApi';
import { expect, test } from './support/test';

test.describe('RouteError', () => {
    test('A request without a response shows "Connection failed", and Try again shows the screen', async ({ page, api }) => {
        new FakeRepositories(api).repositories.push(localRepository('kept-repository'));
        let aborted = false;
        await page.route(
            (url) => '/api/v1/repositories' === url.pathname,
            (route) => {
                if (aborted) {
                    return route.fallback();
                }
                aborted = true;

                return route.abort('failed');
            },
        );

        await page.goto('/repositories');
        const routeError = page.getByTestId('route-error');
        await expect(routeError.getByRole('heading')).toHaveText('Connection failed');
        await expect(routeError).toContainText('Slopmower did not answer. Check your connection and try again.');
        await expect(routeError).not.toContainText('Failed to fetch');
        await page.getByRole('button', { name: 'Try again' }).click();

        await expect(page.getByTestId('route-error')).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'kept-repository' })).toBeVisible();
    });

    test('The signed-out screen offers no Try again, and Sign in loads the same page again', async ({ page, api }) => {
        new FakeRepositories(api);
        api.on('GET', '/api/v1/me', () => apiError(401, 'unauthorized', 'Sign in first.'));

        await page.goto('/repositories');

        await expect(page.getByTestId('route-error').getByRole('heading')).toHaveText('Signed out');
        await expect(page.getByRole('button', { name: 'Try again' })).toHaveCount(0);
        const documentLoad = page.waitForRequest((request) => request.isNavigationRequest() && request.frame() === page.mainFrame());
        await page.getByRole('button', { name: 'Sign in' }).click();
        expect(new URL((await documentLoad).url()).pathname).toBe('/repositories');
    });
});
