import type { CandidateList } from '../../assets/api/types/lists';
import { fixture } from './support/builders';
import { FakeRepositories } from './support/fakeRepositories';
import { expect, test } from './support/test';

const SECTION = '[data-testid="github-candidates"]';

test.describe('GitHubCandidates', () => {
    test('GitHub candidates, Refresh to the setup URL, and the Configure on GitHub link show on the repositories screen', async ({ page, api }) => {
        const candidates = fixture<CandidateList>('lists.candidate-list.json');
        const repositories = new FakeRepositories(api);
        repositories.addLocal('billing');
        repositories.candidates = candidates.items.filter(({ source }) => 'github' === source).map((candidate) => ({ ...candidate, connectedRepositoryId: null }));
        repositories.candidateFields = { githubListedAt: candidates.githubListedAt, githubConfigureUrl: candidates.githubConfigureUrl };

        await page.goto('/repositories');

        await expect(page.locator(`${SECTION} button[aria-label="Connect acme/shipping"]`)).toBeVisible();
        await expect(page.locator(`${SECTION} table`)).toContainText('acme/billing');
        await expect(page.locator(`${SECTION} [data-testid="github-configure"]`)).toHaveAttribute('href', 'https://github.com/apps/slopmower/installations/new');
        await expect(page.locator(`${SECTION} [data-testid="github-configure"]`)).toContainText('Configure on GitHub');
        await expect(page.locator(SECTION).getByRole('link', { name: 'Refresh' })).toHaveAttribute('href', '/github/installed');
    });

    test('Connect is disabled until a mode is picked, and the request body carries it', async ({ page, api }) => {
        const candidates = fixture<CandidateList>('lists.candidate-list.json');
        const repositories = new FakeRepositories(api);
        repositories.addLocal('billing');
        repositories.candidates = candidates.items.filter(({ source }) => 'github' === source).map((candidate) => ({ ...candidate, connectedRepositoryId: null }));
        repositories.candidateFields = { githubListedAt: candidates.githubListedAt, githubConfigureUrl: candidates.githubConfigureUrl };
        await page.goto('/repositories');
        const connect = page.locator(SECTION).getByRole('button', { name: 'Connect acme/shipping' });
        await expect(connect).toBeDisabled();

        await page.locator(SECTION).getByRole('radio', { name: 'Pass-through' }).check();
        await connect.click();

        const row = page.locator('[data-testid="connected-repositories"] tr').filter({ hasText: 'acme/shipping' });
        await expect(row.locator('[data-testid="sync-state"]')).toHaveText('Connecting');
        expect(api.bodies('POST', '/api/v1/repositories')).toEqual([{ source: 'github', githubRepositoryId: 812399, codeAccess: 'pass_through' }]);
    });
});
