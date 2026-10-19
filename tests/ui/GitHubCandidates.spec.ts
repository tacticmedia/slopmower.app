import type { CandidateList } from '../../assets/api/types/lists';
import { fixture } from './support/builders';
import { FakeRepositories } from './support/fakeRepositories';
import { expect, test } from './support/test';

const SECTION = '[data-testid="github-candidates"]';

test.describe('GitHubCandidates', () => {
    test('GitHub candidates and the Configure on GitHub link show on the repositories screen', async ({ page, api }) => {
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
    });
});
