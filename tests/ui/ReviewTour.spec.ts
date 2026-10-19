import type { Page } from '@playwright/test';
import type { KnownTour } from '../../assets/api/types/common';
import { lineNumber, openFile } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { SMALL_FILE } from './support/files';
import { json, type MockApi } from './support/mockApi';
import { expect, test } from './support/test';

const PREFERENCES = '/api/v1/me/preferences';

const CODE_BROWSER_STEPS = ['File tree', 'Folders and files', 'Quick open', 'Requests', 'Agent prompts'];

function tour(page: Page) {
    return page.getByTestId('review-tour');
}

/** The user has completed these parts, and has no API token. */
function completed(api: MockApi, completedTours: KnownTour[]): FakeReview {
    api.me = { ...api.me, preferences: { completedTours } };
    api.on('GET', '/api/v1/tokens', () => json('lists.schema.json#/$defs/TokenList', { items: [] }));

    return new FakeReview(api);
}

async function expectStep(page: Page, title: string, progress: string): Promise<void> {
    await expect(page.getByRole('dialog', { name: title })).toBeVisible();
    await expect(tour(page)).toContainText(progress);
}

test.describe('ReviewTour', () => {
    test('Without a completed part, the code browser steps show in order', async ({ page, api }) => {
        const review = completed(api, []);

        await page.goto(`/reviews/${review.id}`);

        for (const [index, title] of CODE_BROWSER_STEPS.entries()) {
            await expectStep(page, title, `${index + 1} of ${CODE_BROWSER_STEPS.length}`);
            if (index < CODE_BROWSER_STEPS.length - 1) {
                await tour(page).getByRole('button', { name: 'Next' }).click();
            }
        }
        await expect(tour(page).getByRole('link', { name: 'Create an API token in Settings' })).toHaveAttribute('href', '/settings');
        await expect(tour(page).getByRole('button', { name: 'Done' })).toBeVisible();
    });

    test('Done completes the code browser part', async ({ page, api }) => {
        const review = completed(api, []);
        await page.goto(`/reviews/${review.id}`);
        for (const title of CODE_BROWSER_STEPS.slice(0, -1)) {
            await expectStep(page, title, 'of 5');
            await tour(page).getByRole('button', { name: 'Next' }).click();
        }

        await tour(page).getByRole('button', { name: 'Done' }).click();

        await expect(tour(page)).toHaveCount(0);
        await expect.poll(() => api.bodies('PATCH', PREFERENCES)).toEqual([{ completedTours: ['code_browser'] }]);
    });

    test('"Skip tour" completes both parts', async ({ page, api }) => {
        const review = completed(api, []);
        await page.goto(`/reviews/${review.id}`);

        await tour(page).getByRole('button', { name: 'Skip tour' }).click();

        await expect(tour(page)).toHaveCount(0);
        await expect.poll(() => api.bodies('PATCH', PREFERENCES)).toEqual([{ completedTours: ['code_browser', 'file'] }]);
        await openFile(page, review, SMALL_FILE);
        await expect(lineNumber(page, 1)).toBeVisible();
        await expect(tour(page)).toHaveCount(0);
    });

    test('The file part starts on the first file view and points at the line number of the gutter "+"', async ({ page, api }) => {
        const review = completed(api, ['code_browser']);

        await openFile(page, review, SMALL_FILE);

        await expectStep(page, 'Comment on code', '1 of 3');
        const number = await lineNumber(page, 1).boundingBox();
        const tooltip = await tour(page).boundingBox();
        expect(null !== number && null !== tooltip && tooltip.x >= number.x + number.width && tooltip.y <= number.y && number.y <= tooltip.y + tooltip.height).toBe(true);
        await tour(page).getByRole('button', { name: 'Next' }).click();
        await expectStep(page, 'Symbols', '2 of 3');
        await tour(page).getByRole('button', { name: 'Next' }).click();
        await expectStep(page, 'Go to declaration', '3 of 3');
    });

    test('Escape completes the current part', async ({ page, api }) => {
        const review = completed(api, []);
        await page.goto(`/reviews/${review.id}`);
        await expectStep(page, 'File tree', '1 of 5');

        await page.keyboard.press('Escape');

        await expect(tour(page)).toHaveCount(0);
        await expect.poll(() => api.bodies('PATCH', PREFERENCES)).toEqual([{ completedTours: ['code_browser'] }]);
    });

    test('The focus moves into the tooltip, the keyboard works it, and the focus returns when it closes', async ({ page, api }) => {
        const review = completed(api, ['code_browser']);
        await page.goto(`/reviews/${review.id}/tree/src/Billing`);
        const treeItem = page.locator(`[data-testid="file-tree"] [data-path="${SMALL_FILE}"]`);

        await treeItem.click();

        await expect(tour(page).getByRole('button', { name: 'Next' })).toBeFocused();
        await page.keyboard.press('Enter');
        await expectStep(page, 'Symbols', '2 of 3');
        await page.keyboard.press('Escape');
        await expect(tour(page)).toHaveCount(0);
        await expect(treeItem).toBeFocused();
    });

    test('A navigation away from the directory completes the code browser part, and the file part starts', async ({ page, api }) => {
        const review = completed(api, []);
        await page.goto(`/reviews/${review.id}`);
        await tour(page).getByRole('button', { name: 'Next' }).click();
        await expectStep(page, 'Folders and files', '2 of 5');

        await page.getByRole('table', { name: 'Folders and files' }).getByRole('link', { name: 'README.md', exact: true }).click();

        await expectStep(page, 'Comment on code', '1 of 3');
        await expect.poll(() => api.bodies('PATCH', PREFERENCES)).toEqual([{ completedTours: ['code_browser'] }]);
    });

    test('With both parts completed, no tour shows', async ({ page, api }) => {
        const review = new FakeReview(api);
        await page.goto(`/reviews/${review.id}`);
        await expect(page.getByRole('table', { name: 'Folders and files' })).toBeVisible();

        await openFile(page, review, SMALL_FILE);

        await expect(lineNumber(page, 1)).toBeVisible();
        await expect(tour(page)).toHaveCount(0);
        expect(api.callsTo('GET', '/api/v1/tokens')).toEqual([]);
    });
});
