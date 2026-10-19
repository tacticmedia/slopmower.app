import type { LimitUsage } from '../../assets/api/types/me';
import { me } from './support/builders';
import { FakeRepositories } from './support/fakeRepositories';
import { expect, test } from './support/test';

const USAGE: LimitUsage[] = [
    { limit: 'repositories', max: 25, current: 3 },
    { limit: 'open_reviews', max: 20, current: 2 },
    { limit: 'requests_per_review', max: 1000, current: 14 },
    { limit: 'repository_size', max: 2147483648, current: 48234496 },
    { limit: 'tenant_storage', max: null, current: 61865984 },
];

test.describe('SettingsUsage', () => {
    test('Settings shows the use and the limit of each item', async ({ page, api }) => {
        api.me = { ...me(), usage: USAGE };
        new FakeRepositories(api);

        await page.goto('/settings');

        const rows = page.locator('table[aria-labelledby="usage"] tbody tr');
        await expect(rows).toHaveText([
            /^Connected repositories\s*3\s*25$/,
            /^Open reviews\s*2\s*20$/,
            /^Requests per review\s*14\s*1,000$/,
            /^Size of one repository\s*46 MiB\s*2 GiB$/,
            /^Size of all repositories\s*59 MiB\s*No limit$/,
        ]);
    });
});
