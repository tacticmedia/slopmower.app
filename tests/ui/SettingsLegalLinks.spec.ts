import { FakeRepositories } from './support/fakeRepositories';
import { expect, test } from './support/test';

test.describe('SettingsLegalLinks', () => {
    test('Settings links the privacy policy, the terms of service, and the support page', async ({ page, api }) => {
        new FakeRepositories(api);

        await page.goto('/settings');

        const links = page.getByRole('navigation', { name: 'Legal and support' }).getByRole('link');
        await expect(links).toHaveText(['Privacy policy', 'Terms of service', 'Support']);
        expect(await links.evaluateAll((elements) => elements.map((element) => element.getAttribute('href')))).toEqual(['/docs/privacy', '/docs/terms', '/docs/support']);
    });
});
