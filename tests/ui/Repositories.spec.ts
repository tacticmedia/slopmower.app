import type { Page } from '@playwright/test';
import type { Repository } from '../../assets/api/types/repository';
import { fixture, localCandidate, me, newId } from './support/builders';
import { FakeRepositories } from './support/fakeRepositories';
import { expect, test } from './support/test';

const CANDIDATES = '[data-testid="local-candidates"]';
const CONNECTED = '[data-testid="connected-repositories"]';
const DIRECTORY = 'e2e-candidate';

function connectButton(page: Page, directory: string) {
    return page.locator(`${CANDIDATES} button[aria-label="Connect ${directory}"]`);
}

test.describe('Repositories', () => {
    test('A repository that the test created is listed as a candidate', async ({ page, api }) => {
        new FakeRepositories(api).candidates = [localCandidate(DIRECTORY)];

        await page.goto('/repositories');

        await expect(connectButton(page, DIRECTORY)).toBeVisible();
        await expect(page.locator(`${CANDIDATES} table`)).toContainText(DIRECTORY);
    });

    test('Connect adds it to the connected repositories', async ({ page, api, live }) => {
        const repositories = new FakeRepositories(api);
        repositories.candidates = [localCandidate(DIRECTORY)];
        await page.goto('/repositories');
        await expect(page.locator('[data-live="connected"]')).toBeAttached();

        await connectButton(page, DIRECTORY).click();

        await expect(page.locator(`${CONNECTED} table`)).toContainText(DIRECTORY);
        await expect(page.locator(`${CONNECTED} [data-testid="sync-state"]`)).toContainText('Cloning');
        await expect(page.locator(`${CANDIDATES} table`)).toContainText('Connected');
        expect(api.bodies('POST', '/api/v1/repositories')).toEqual([{ source: 'local', directory: DIRECTORY }]);
        const [repository] = repositories.repositories;
        repositories.finishClone(repository?.id ?? '');
        await live.push({ type: 'repository', repositoryId: repository?.id ?? '' });
        await expect(page.locator(`${CONNECTED} [data-testid="sync-state"]`)).toContainText('Ready');
    });

    test('A GitHub repository shows its owner, its visibility, and a link to GitHub', async ({ page, api }) => {
        const repository = { ...fixture<Repository>('repository.github-cloning.json'), id: newId() };
        new FakeRepositories(api).repositories.push(repository);

        await page.goto(`/repositories/${repository.id}`);

        const details = page.locator('[data-testid="github-details"]');
        await expect(details).toContainText('Private');
        await expect(details).toContainText('Owner acme');
        await expect(details.getByRole('link', { name: 'View on GitHub' })).toHaveAttribute('href', 'https://github.com/acme/billing');
    });

    test('The switch of a GitHub repository turns on the check of every pull request', async ({ page, api }) => {
        const repository = { ...fixture<Repository>('repository.github-cloning.json'), id: newId() };
        new FakeRepositories(api).repositories.push(repository);
        await page.goto(`/repositories/${repository.id}`);
        const toggle = page.getByRole('button', { name: 'Check every pull request' });
        await expect(toggle).toHaveAttribute('aria-pressed', 'false');

        await toggle.click();

        await expect(toggle).toHaveAttribute('aria-pressed', 'true');
        expect(api.bodies('POST', `/api/v1/repositories/${repository.id}/check-every-pull-request`)).toEqual([{ enabled: true }]);
    });

    test('At 390 px and 768 px the review table of a repository does not make the page scroll sideways', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        const repository = repositories.addLocal('billing');
        repositories.addReview(repository, { review: { title: 'Invoice rounding' } });
        await page.goto(`/repositories/${repository.id}`);
        await expect(page.locator('[data-testid="review-list"] table')).toContainText('Invoice rounding');

        for (const width of [390, 768]) {
            await page.setViewportSize({ width, height: 900 });

            await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `No sideways scroll at ${width} px.`).toBe(true);
        }
    });

    test('A notice shows when local repositories are disabled', async ({ page, api }) => {
        api.me = me({ localRepositories: false });
        const repositories = new FakeRepositories(api);
        repositories.candidateFields = { localEnabled: false };

        await page.goto('/repositories');

        await expect(page.locator('[data-testid="local-repositories-off"]')).toBeVisible();
        await expect(page.locator(`${CANDIDATES} table`)).toHaveCount(0);
    });
});
