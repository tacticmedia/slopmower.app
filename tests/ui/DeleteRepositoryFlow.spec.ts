import { FakeRepositories } from './support/fakeRepositories';
import { FakeTokens } from './support/fakeTokens';
import { expect, test } from './support/test';

const REPOSITORY_DATA = 'table[aria-labelledby="repository-data"]';

test.describe('DeleteRepositoryFlow', () => {
    test('Typing the repository name and confirming removes the repository from the list', async ({ page, api }) => {
        const repositories = new FakeRepositories(api);
        new FakeTokens(api, repositories);
        const kept = repositories.addLocal('e2e-kept');
        const deleted = repositories.addLocal('e2e-deleted');
        await page.goto('/settings');
        const table = page.locator(REPOSITORY_DATA);
        await expect(table).toContainText(deleted.name);

        await table.locator('tr').filter({ hasText: deleted.name }).getByRole('button', { name: 'Delete data' }).click();
        await page.locator('[role="dialog"] input').fill(deleted.name);
        await page.locator('[role="dialog"] button[type="submit"]').click();

        await expect(table).not.toContainText(deleted.name);
        await expect(table).toContainText(kept.name);
        expect(api.callsTo('DELETE', '/api/v1/repositories/:repositoryId').map(({ params, query }) => [params.repositoryId, query.get('confirm')])).toEqual([[deleted.id, deleted.name]]);
        await page.goto('/repositories');
        const connected = page.locator('[data-testid="connected-repositories"] table');
        await expect(connected).toContainText(kept.name);
        await expect(connected).not.toContainText(deleted.name);
    });
});
