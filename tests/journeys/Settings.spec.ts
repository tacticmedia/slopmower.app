import type { LimitUsage, Me } from '../../assets/api/types/me';
import { fixture, me, now, token } from '../ui/support/builders';
import { FakeRepositories } from '../ui/support/fakeRepositories';
import { FakeTokens } from '../ui/support/fakeTokens';
import { test } from './support/test';

const USAGE: LimitUsage[] = [
    { limit: 'repositories', max: 25, current: 3 },
    { limit: 'open_reviews', max: 20, current: 2 },
    { limit: 'requests_per_review', max: 1000, current: 14 },
    { limit: 'repository_size', max: 2147483648, current: 48234496 },
    { limit: 'tenant_storage', max: null, current: 61865984 },
];

test.describe('Settings', () => {
    test('Settings list the tokens, issue a new one, and show the plan and its usage', async ({ page, api, captureScreen }) => {
        const repositories = new FakeRepositories(api);
        const billing = repositories.addLocal('billing');
        repositories.addLocal('shipping');
        const tokens = new FakeTokens(api, repositories);
        tokens.tokens = [
            token({ name: 'claude-code laptop', lastUsedAt: now(-120) }),
            token({ name: 'ci billing', scope: 'repository', repositoryId: billing.id, repositoryName: billing.name, expiresAt: now(30 * 86_400) }),
            token({ name: 'old desktop', state: 'revoked', revokedAt: now(-7 * 86_400) }),
        ];

        await page.goto('/settings');
        await captureScreen('API tokens', page.locator('table[aria-labelledby="api-tokens"]'));

        await page.getByRole('button', { name: 'Create token' }).click();
        await page.getByRole('dialog').locator('input[name="name"]').fill('codex desktop');
        await captureScreen('Create token', page.getByRole('dialog').locator('select[name="repositoryId"]'));

        await page.getByRole('dialog').getByRole('button', { name: 'Create', exact: true }).click();
        await captureScreen('Issued token', page.locator('[data-testid="token-secret"]'));

        api.me = { ...me(), plan: fixture<Me>('me.agent.json').plan, usage: USAGE };
        await page.goto('/settings');
        await captureScreen('Paid plan and usage', page.getByTestId('plan').filter({ hasText: 'Your plan is Pro.' }));
    });
});
