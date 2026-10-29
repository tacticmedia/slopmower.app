import type { Page } from '@playwright/test';
import type { CandidateList } from '../../assets/api/types/lists';
import { fixture, localCandidate, token } from './support/builders';
import { FakeRepositories } from './support/fakeRepositories';
import { expect, test } from './support/test';

/** The right border width of the scroll region of each table and whether the region ends inside the window. */
function frames(page: Page): Promise<{ rightBorder: string; inside: boolean }[]> {
    return page.evaluate(() =>
        Array.from(document.querySelectorAll('.TableOverflowWrapper')).map((region) => ({
            rightBorder: getComputedStyle(region).borderRightWidth,
            inside: region.getBoundingClientRect().right <= window.innerWidth,
        })),
    );
}

test.describe('TableFrames', () => {
    test.use({ viewport: { width: 390, height: 900 } });

    test('At 390 px each table of Settings and of the repositories page draws its right edge inside the window', async ({ page, api }) => {
        const candidates = fixture<CandidateList>('lists.candidate-list.json');
        const repositories = new FakeRepositories(api);
        repositories.addLocal('billing');
        repositories.tokens.tokens = [token()];
        repositories.candidates = [localCandidate('e2e-candidate'), ...candidates.items.filter(({ source }) => 'github' === source).map((candidate) => ({ ...candidate, connectedRepositoryId: null }))];
        repositories.candidateFields = { githubListedAt: candidates.githubListedAt, githubConfigureUrl: candidates.githubConfigureUrl };

        await page.goto('/settings');
        await expect(page.locator('table[aria-labelledby="usage"]')).toBeVisible();
        expect(await frames(page)).toEqual(Array(3).fill({ rightBorder: '1px', inside: true }));

        await page.goto('/repositories');
        await expect(page.locator('[data-testid="github-candidates"] table')).toBeVisible();
        expect(await frames(page)).toEqual(Array(3).fill({ rightBorder: '1px', inside: true }));
    });
});
