import type { CandidateList } from '../../assets/api/types/lists';
import type { Me } from '../../assets/api/types/me';
import { fixture, localCandidate, me, now, token } from './support/builders';
import { FakeRepositories } from './support/fakeRepositories';
import type { MockApi } from './support/mockApi';
import { expect, test } from './support/test';

const STEPS = '[data-testid="onboarding-steps"]';
const STEP = '[data-testid="onboarding-step"]';
const GITHUB_APP: NonNullable<Me['features']['githubApp']> = { slug: 'slopmower', installUrl: 'https://github.com/apps/slopmower/installations/new' };

/** A hosted instance: the GitHub App, no local directories. */
function hosted(api: MockApi): FakeRepositories {
    api.me = me({ githubApp: GITHUB_APP, localRepositories: false });
    const repositories = new FakeRepositories(api);
    repositories.candidateFields = { localEnabled: false, githubConfigureUrl: fixture<CandidateList>('lists.candidate-list.json').githubConfigureUrl };

    return repositories;
}

test.describe('Onboarding', () => {
    test('Without GitHub candidates, Install the GitHub App links to the install URL', async ({ page, api }) => {
        hosted(api);

        await page.goto('/repositories');

        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Welcome to Slopmower');
        await expect(page.locator(`${STEP}[aria-current="step"]`)).toContainText('Connect a repository');
        await expect(page.getByRole('link', { name: 'Install the GitHub App' })).toHaveAttribute('href', GITHUB_APP.installUrl);
        await expect(page.locator('[data-testid="connected-repositories"]')).toHaveCount(0);
    });

    test('With GitHub candidates, Connect posts R3 and opens the repository', async ({ page, api }) => {
        const repositories = hosted(api);
        const candidates = fixture<CandidateList>('lists.candidate-list.json');
        repositories.candidates = candidates.items.filter(({ source }) => 'github' === source).map((candidate) => ({ ...candidate, connectedRepositoryId: null }));
        repositories.candidateFields = { ...repositories.candidateFields, githubListedAt: now(-600) };
        await page.goto('/repositories');

        await page.getByRole('button', { name: 'Connect acme/shipping' }).click();

        await expect(page).toHaveURL(/\/repositories\/[0-9A-HJKMNP-TV-Z]{26}$/);
        expect(new URL(page.url()).pathname).toBe(`/repositories/${repositories.repositories.at(-1)?.id}`);
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('acme/shipping');
        expect(api.bodies('POST', '/api/v1/repositories')).toEqual([{ source: 'github', githubRepositoryId: 812399 }]);
    });

    test('Local candidates show with Connect', async ({ page, api }) => {
        new FakeRepositories(api).candidates = [localCandidate('billing'), localCandidate('shipping')];

        await page.goto('/repositories');

        const local = page.locator('[data-testid="onboarding-local"]');
        await expect(local.getByRole('button', { name: 'Connect billing' })).toBeVisible();
        await expect(local.getByRole('button', { name: 'Connect shipping' })).toBeVisible();
    });

    test('Without a GitHub App and local directories, an info banner names the missing source', async ({ page, api }) => {
        api.me = me({ localRepositories: false });
        new FakeRepositories(api).candidateFields = { localEnabled: false };

        await page.goto('/repositories');

        const banner = page.locator('[data-testid="no-repository-source"]');
        await expect(banner).toContainText('This instance has no repository source');
        await expect(banner).toContainText('The operator sets up a GitHub App or APP_LOCAL_REPOSITORIES_DIR.');
    });

    test('With an active token, step 4 is done', async ({ page, api }) => {
        new FakeRepositories(api).tokens.tokens = [token()];

        await page.goto('/repositories');

        await expect(page.locator(STEP).filter({ hasText: 'Connect your agent' })).toHaveAttribute('data-state', 'done');
        await expect(page.locator(STEPS).getByRole('heading', { level: 2 })).toHaveText('Getting started, step 1 of 4');
    });

    test('A revoked token leaves step 4 to do', async ({ page, api }) => {
        new FakeRepositories(api).tokens.tokens = [token({ state: 'revoked', revokedAt: now(-60) })];

        await page.goto('/repositories');

        await expect(page.locator(STEP).filter({ hasText: 'Connect your agent' })).toHaveAttribute('data-state', 'upcoming');
    });

    test('/ opens /repositories', async ({ page, api }) => {
        new FakeRepositories(api);

        await page.goto('/');

        await expect(page).toHaveURL('/repositories');
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Welcome to Slopmower');
    });
});
