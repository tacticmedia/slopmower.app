import { FakeRepositories } from './support/fakeRepositories';
import { FakeTokens } from './support/fakeTokens';
import { expect, test } from './support/test';

test.describe('SettingsTour', () => {
    test('The switch shows whether the tour shows, and each change sends A4', async ({ page, api }) => {
        new FakeTokens(api, new FakeRepositories(api));
        await page.goto('/settings');
        const toggle = page.getByRole('button', { name: 'Show the review tour' });
        await expect(toggle).toHaveAttribute('aria-pressed', 'false');

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-pressed', 'true');
        // The switch takes no change while the write runs.
        await expect(toggle).toHaveAttribute('aria-disabled', 'false');
        await toggle.click();

        await expect(toggle).toHaveAttribute('aria-pressed', 'false');
        expect(api.bodies('PATCH', '/api/v1/me/preferences')).toEqual([{ completedTours: [] }, { completedTours: ['code_browser', 'file'] }]);
    });
});
