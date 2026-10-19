import type { Page } from '@playwright/test';
import { FakeRepositories } from './support/fakeRepositories';
import { FakeReview } from './support/fakeReview';
import { json } from './support/mockApi';
import { expect, test } from './support/test';

const PREFERENCES = '/api/v1/me/preferences';

async function openHelp(page: Page): Promise<void> {
    await page.getByRole('button', { name: 'Help' }).click();
    await expect(page.getByRole('menu')).toBeVisible();
}

test.describe('HelpMenu', () => {
    test('The Help menu links the help pages in a new tab, and has no tour outside a review', async ({ page, api }) => {
        new FakeRepositories(api);
        await page.goto('/repositories');

        await openHelp(page);

        const items = page.getByRole('menuitem');
        await expect(items).toHaveText(['Help center', 'Keyboard shortcuts', 'Connect your agent', 'Support']);
        expect(await items.evaluateAll((elements) => elements.map((element) => [element.getAttribute('href'), element.getAttribute('target')]))).toEqual([
            ['/docs', '_blank'],
            ['/docs/keyboard-shortcuts', '_blank'],
            ['/docs/connect-your-agent', '_blank'],
            ['/docs/support', '_blank'],
        ]);
    });

    test('"Take the review tour" sends A4 with no part, and a part that ended in this visit starts again', async ({ page, api }) => {
        api.me = { ...api.me, preferences: { completedTours: [] } };
        api.on('GET', '/api/v1/tokens', () => json('lists.schema.json#/$defs/TokenList', { items: [] }));
        const review = new FakeReview(api);
        await page.goto(`/reviews/${review.id}`);
        const tour = page.getByTestId('review-tour');
        await expect(page.getByRole('dialog', { name: 'File tree' })).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(tour).toHaveCount(0);
        await expect.poll(() => api.bodies('PATCH', PREFERENCES)).toEqual([{ completedTours: ['code_browser'] }]);

        await openHelp(page);
        await page.getByRole('menuitem', { name: 'Take the review tour' }).click();

        await expect(page.getByRole('dialog', { name: 'File tree' })).toBeVisible();
        await expect(tour).toContainText('1 of 5');
        expect(api.bodies('PATCH', PREFERENCES)).toEqual([{ completedTours: ['code_browser'] }, { completedTours: [] }]);
    });
});
