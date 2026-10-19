import type { Me } from '../../assets/api/types/me';
import { fixture, me } from './support/builders';
import { FakeRepositories } from './support/fakeRepositories';
import { expect, test } from './support/test';

test.describe('SettingsPlan', () => {
    test('Settings shows the plan, the trial end, the pending change, and the listing link', async ({ page, api }) => {
        api.me = { ...me(), plan: fixture<Me>('me.agent.json').plan };
        new FakeRepositories(api);

        await page.goto('/settings');

        await expect(page.getByTestId('plan')).toHaveText(/^Your plan is Pro\. The free trial ends on Oct 13, 2026\. It changes to Team on Nov 13, 2026\. /);
        await expect(page.getByRole('link', { name: 'Change the plan on GitHub Marketplace' })).toHaveAttribute('href', 'https://github.com/marketplace/slopmower');
    });
});
