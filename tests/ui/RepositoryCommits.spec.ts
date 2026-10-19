import type { Repository } from '../../assets/api/types/repository';
import { commits, fixture, newId } from './support/builders';
import { FakeRepositories } from './support/fakeRepositories';
import { expect, test } from './support/test';

const LATEST = '[data-testid="latest-commits"]';
const COMMITS = '/api/v1/repositories/:repositoryId/commits';
const START = '/api/v1/repositories/:repositoryId/reviews';

test.describe('RepositoryCommits', () => {
    test('The latest commits of the default branch are listed', async ({ page, api }) => {
        const repository = new FakeRepositories(api).addLocal('e2e-commits');

        await page.goto(`/repositories/${repository.id}`);

        const latest = page.locator(LATEST);
        await expect(latest.getByRole('heading', { name: 'Latest commits on main' })).toBeVisible();
        await expect(latest).toContainText('As of the last fetch');
        await expect(latest.getByRole('listitem')).toHaveCount(2);
        await expect(latest.getByRole('listitem').first()).toContainText('Round tax per line');
        await expect(latest.getByRole('listitem').first()).toContainText('Grace Hopper committed');
        await expect(latest.getByRole('listitem').first()).toContainText('79f8595a4c1e');
        expect(api.callsTo('GET', COMMITS).map((call) => call.query.toString())).toEqual(['']);
    });

    test('Start review on a commit posts V1 with its full SHA and opens the review', async ({ page, api }) => {
        const repository = new FakeRepositories(api).addLocal('e2e-commits');
        const [newest] = commits();
        await page.goto(`/repositories/${repository.id}`);

        await page.getByRole('button', { name: `Start review at ${newest?.sha.slice(0, 12)}` }).click();

        await expect(page).toHaveURL(/\/reviews\/[0-9A-HJKMNP-TV-Z]{26}$/);
        expect(api.bodies('POST', START)).toEqual([{ ref: newest?.sha, title: null }]);
    });

    test('A repository whose clone is not ready says when the commits show', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const repository = repositories.addLocal('e2e-commits');
        repositories.repositories = [{ ...repository, sync: { ...repository.sync, state: 'cloning' } } as Repository];

        await page.goto(`/repositories/${repository.id}`);

        await expect(page.locator(LATEST)).toContainText('Commits show when the clone is ready.');
        await expect(page.locator(`${LATEST} li`)).toHaveCount(0);
    });

    test('A repository without a default branch has no latest commits', async ({ page, api }) => {
        const repository = { ...fixture<Repository>('repository.github-cloning.json'), id: newId() };
        new FakeRepositories(api).repositories.push(repository);

        await page.goto(`/repositories/${repository.id}`);

        await expect(page.locator('[data-testid="start-review-form"]')).toBeVisible();
        await expect(page.locator(LATEST)).toHaveCount(0);
        expect(api.callsTo('GET', COMMITS)).toHaveLength(0);
    });

    test('A long subject is cut to one line, and the page does not scroll sideways at 390 px', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const repository = repositories.addLocal('e2e-commits');
        const subject = 'Round the tax of every invoice line before the sum, '.repeat(6).trim();
        repositories.commits.set(repository.id, commits().map((commit) => ({ ...commit, subject })));
        await page.setViewportSize({ width: 390, height: 844 });

        await page.goto(`/repositories/${repository.id}`);

        const cut = page.locator(LATEST).getByTitle(subject).first();
        await expect(cut).toBeVisible();
        expect(await cut.evaluate((element) => element.scrollWidth > element.clientWidth)).toBe(true);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    });
});
