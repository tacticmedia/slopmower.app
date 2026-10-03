import type { Page } from '@playwright/test';
import { localCandidate, me } from './support/builders';
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

    test('A notice shows when local repositories are disabled', async ({ page, api }) => {
        api.me = me({ localRepositories: false });
        const repositories = new FakeRepositories(api);
        repositories.candidateFields = { localEnabled: false };

        await page.goto('/repositories');

        await expect(page.locator('[data-testid="local-repositories-off"]')).toBeVisible();
        await expect(page.locator(`${CANDIDATES} table`)).toHaveCount(0);
    });
});
