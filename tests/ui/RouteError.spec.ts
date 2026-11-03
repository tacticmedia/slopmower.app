import { localRepository } from './support/builders';
import { FakeRepositories } from './support/fakeRepositories';
import { apiError } from './support/mockApi';
import { expect, test } from './support/test';

test.describe('RouteError', () => {
    test('Try again after a request without a response shows the screen', async ({ page, api }) => {
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
        await expect(page.getByTestId('route-error')).toContainText('Failed to fetch');
        await page.getByRole('button', { name: 'Try again' }).click();

        await expect(page.getByTestId('route-error')).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'kept-repository' })).toBeVisible();
    });

    test('The signed-out screen offers Sign in and no Try again', async ({ page, api }) => {
        new FakeRepositories(api);
        api.on('GET', '/api/v1/me', () => apiError(401, 'unauthorized', 'Sign in first.'));

        await page.goto('/repositories');

        await expect(page.getByTestId('route-error').getByRole('heading')).toHaveText('Signed out');
        await expect(page.getByRole('link', { name: 'Sign in' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Try again' })).toHaveCount(0);
    });
});
