import type { CandidateList } from '../../assets/api/types/lists';
import type { Repository } from '../../assets/api/types/repository';
import { fixture, newId, now, sha1 } from '../ui/support/builders';
import { FakeRepositories } from '../ui/support/fakeRepositories';
import { test } from './support/test';

const CONNECTED = '[data-testid="connected-repositories"]';

/** A local repository whose last fetch failed. */
function failedRepository(): Repository {
    const base = fixture<Repository>('repository.local-idle.json');

    return {
        ...base,
        id: newId(),
        name: 'invoices',
        local: { directory: 'invoices' },
        sync: { state: 'failed', lastFetchedAt: now(-7200), lastError: "fatal: couldn't find remote ref refs/heads/main", defaultBranchHead: sha1('invoices') },
        openReviewCount: 0,
    } as Repository;
}

test.describe('Repositories', () => {
    test('An empty list fills, and a repository lists its reviews', async ({ page, api, captureScreen }) => {
        const repositories = new FakeRepositories(api);
        const candidates = fixture<CandidateList>('lists.candidate-list.json');
        repositories.candidateFields = { githubListedAt: null, githubConfigureUrl: candidates.githubConfigureUrl };

        await page.goto('/repositories');
        await captureScreen('No repositories', page.locator(`${CONNECTED} #connected-repositories-empty`));

        const local = { ...fixture<Repository>('repository.local-idle.json'), openReviewCount: 2 } as Repository;
        repositories.repositories = [fixture<Repository>('repository.github-cloning.json'), local, failedRepository()];
        repositories.candidates = candidates.items;
        repositories.candidateFields = { githubListedAt: now(-600), githubConfigureUrl: candidates.githubConfigureUrl };
        await page.reload();
        await captureScreen('Connected repositories and candidates', page.locator(`${CONNECTED} table`));

        repositories.addReview(local, { review: { title: 'Invoice rounding', createdAt: now(-86_400) } });
        repositories.addReview(local, { review: { title: 'main @ c0ffee012345', createdAt: now(-3600) } });
        await page.locator(CONNECTED).getByRole('link', { name: local.name, exact: true }).click();
        await captureScreen('Repository with open reviews', page.locator('[data-testid="review-list"] table'));
    });
});
