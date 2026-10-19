import type { CandidateList } from '../../assets/api/types/lists';
import type { Repository } from '../../assets/api/types/repository';
import { fixture, localCandidate, me, newId, now, sha1 } from '../ui/support/builders';
import { FakeRepositories } from '../ui/support/fakeRepositories';
import { test } from './support/test';

const CONNECTED = '[data-testid="connected-repositories"]';
const STEPS = '[data-testid="onboarding-steps"]';

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
    test('The onboarding shows the repository sources of the instance', async ({ page, api, captureScreen }) => {
        const candidates = fixture<CandidateList>('lists.candidate-list.json');
        api.me = me({ localRepositories: false, githubApp: { slug: 'slopmower', installUrl: candidates.githubConfigureUrl ?? '' } });
        const repositories = new FakeRepositories(api);
        repositories.candidateFields = { localEnabled: false, githubListedAt: null, githubConfigureUrl: candidates.githubConfigureUrl };

        await page.goto('/repositories');
        await captureScreen('Onboarding, GitHub, no installation', page.locator(STEPS));

        repositories.candidates = candidates.items.filter(({ source }) => 'github' === source).map((candidate) => ({ ...candidate, connectedRepositoryId: null }));
        repositories.candidateFields = { ...repositories.candidateFields, githubListedAt: now(-600) };
        await page.reload();
        await captureScreen('Onboarding, candidates', page.locator(`${STEPS} [data-testid="onboarding-github"] ul`));

        api.me = me();
        repositories.candidates = [...candidates.items.filter(({ source }) => 'local' === source).map((candidate) => ({ ...candidate, connectedRepositoryId: null })), localCandidate('shipping')];
        repositories.candidateFields = {};
        await page.reload();
        await captureScreen('Onboarding, local', page.locator(`${STEPS} [data-testid="onboarding-local"] ul`));

        api.me = me({ localRepositories: false });
        repositories.candidates = [];
        repositories.candidateFields = { localEnabled: false };
        await page.reload();
        await captureScreen('Onboarding, no repository source', page.locator('[data-testid="no-repository-source"]'));
    });

    test('Connected repositories list the candidates, and a repository lists its reviews', async ({ page, api, captureScreen }) => {
        const repositories = new FakeRepositories(api);
        const candidates = fixture<CandidateList>('lists.candidate-list.json');
        const local = { ...fixture<Repository>('repository.local-idle.json'), openReviewCount: 2 } as Repository;
        repositories.repositories = [fixture<Repository>('repository.github-cloning.json'), local, failedRepository()];
        repositories.candidates = candidates.items;
        repositories.candidateFields = { githubListedAt: now(-600), githubConfigureUrl: candidates.githubConfigureUrl };

        await page.goto('/repositories');
        await captureScreen('Connected repositories and candidates', page.locator(`${CONNECTED} table`));

        repositories.addReview(local, { review: { title: 'Invoice rounding', createdAt: now(-86_400) } });
        repositories.addReview(local, { review: { title: 'main @ c0ffee012345', createdAt: now(-3600) } });
        await page.locator(CONNECTED).getByRole('link', { name: local.name, exact: true }).click();
        await captureScreen('Repository with open reviews', page.locator('[data-testid="review-list"] table'));
    });

    test('A repository without a review shows the getting started steps next to its latest commits', async ({ page, api, captureScreen }) => {
        const repository = new FakeRepositories(api).addLocal('billing');

        await page.goto(`/repositories/${repository.id}`);
        await captureScreen('Repository, getting started', page.locator(STEPS));
    });

    test('An instance without local repositories lists the GitHub candidates only', async ({ page, api, captureScreen }) => {
        api.me = me({ localRepositories: false });
        const repositories = new FakeRepositories(api);
        const candidates = fixture<CandidateList>('lists.candidate-list.json');
        repositories.repositories = [fixture<Repository>('repository.github-cloning.json')];
        repositories.candidates = candidates.items.filter(({ source }) => 'github' === source);
        repositories.candidateFields = { localEnabled: false, githubListedAt: now(-600), githubConfigureUrl: candidates.githubConfigureUrl };

        await page.goto('/repositories');
        await captureScreen('Without local repositories', page.locator('[data-testid="github-candidates"] table'));
    });
});
