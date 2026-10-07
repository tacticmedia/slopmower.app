import type { Locator, Page } from '@playwright/test';
import type { SearchHit } from '../../assets/api/types/search-hit';
import { searchHitList } from './support/builders';
import { COMPOSER, FILE_THREADS, location, openFile, selectedLines, tabUntil } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { NESTED_FILE, ROUND_FQN, SMALL_FILE, SYMBOL_FILE } from './support/files';
import { json, type MockApi } from './support/mockApi';
import { expect, test } from './support/test';

const SEARCH = '/api/v1/reviews/:reviewId/search';

const TAX_RATE_FILE: SearchHit = { kind: 'file', path: NESTED_FILE };

/** `round()` declares itself on line 14, below its doc comment. */
const ROUND: SearchHit = { kind: 'symbol', path: SYMBOL_FILE, fqn: ROUND_FQN, symbolKind: 'method', declarationStartLine: 14 };

/** V16 answers every query with `hits`. */
function reviewWithHits(api: MockApi, hits: SearchHit[], partial = false): FakeReview {
    const review = new FakeReview(api);
    api.on('GET', SEARCH, () => json('lists.schema.json#/$defs/SearchHitList', searchHitList(review.id, hits, partial)));

    return review;
}

function dialog(page: Page): Locator {
    return page.getByRole('dialog', { name: 'Quick open' });
}

function searchField(page: Page): Locator {
    return dialog(page).getByRole('combobox', { name: 'File or symbol name' });
}

/** Types the query and waits until its hits are listed. */
async function search(page: Page, query: string, firstHit: string): Promise<void> {
    await expect(searchField(page)).toBeFocused();
    await page.keyboard.type(query);
    await expect(dialog(page).getByRole('option').first()).toContainText(firstHit);
}

test.describe('QuickOpen', () => {
    test('Cmd+K opens Quick open, and Enter opens the first file hit', async ({ page, api }) => {
        const review = reviewWithHits(api, [TAX_RATE_FILE, ROUND]);
        await openFile(page, review, SMALL_FILE);

        await page.keyboard.press('ControlOrMeta+k');
        await search(page, 'TaxRate', NESTED_FILE);
        await page.keyboard.press('Enter');

        await expect.poll(() => location(page)).toBe(review.fileUrl(NESTED_FILE));
        await expect(dialog(page)).toHaveCount(0);
        expect(api.callsTo('GET', SEARCH).at(-1)?.query.get('q')).toBe('TaxRate');
    });

    test('A symbol hit opens its file with the declaration line selected', async ({ page, api }) => {
        const review = reviewWithHits(api, [ROUND, TAX_RATE_FILE]);
        await openFile(page, review, SMALL_FILE);

        await page.keyboard.press('ControlOrMeta+k');
        await search(page, 'round', ROUND_FQN);
        await page.keyboard.press('Enter');

        await expect.poll(() => location(page)).toBe(review.fileUrl(SYMBOL_FILE, '#L14'));
        await expect.poll(() => selectedLines(page)).toEqual(['14']);
    });

    test('By keyboard only, the arrow keys choose a hit and Enter opens it', async ({ page, api }) => {
        const review = reviewWithHits(api, [TAX_RATE_FILE, ROUND]);
        await openFile(page, review, SMALL_FILE);

        await tabUntil(page, () => page.evaluate(() => document.activeElement?.textContent?.startsWith('Quick open') ?? false), 'the "Quick open" button');
        await page.keyboard.press('Enter');
        await search(page, 'ta', NESTED_FILE);
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('Enter');

        await expect.poll(() => location(page)).toBe(review.fileUrl(SYMBOL_FILE, '#L14'));
    });

    test('Cmd+K in a text field does not open Quick open', async ({ page, api }) => {
        const review = new FakeReview(api);
        await openFile(page, review, SMALL_FILE);
        await page.getByRole('button', { name: 'Comment on file' }).click();
        const commentBox = page.locator(`${FILE_THREADS} ${COMPOSER}`);
        await expect(commentBox).toBeFocused();

        await page.keyboard.press('ControlOrMeta+k');
        await page.keyboard.type('x');

        await expect(commentBox).toHaveValue('x');
        await expect(dialog(page)).toHaveCount(0);
    });

    test('In focus mode, Cmd+K opens Quick open, and Escape closes only the dialog', async ({ page, api }) => {
        const review = reviewWithHits(api, [TAX_RATE_FILE]);
        await openFile(page, review, SMALL_FILE);
        const focusToggle = page.locator('[data-testid="file-header"]').getByRole('button', { name: 'Focus' });
        await focusToggle.click();
        await expect(page.getByRole('heading', { level: 1 })).toBeHidden();

        await page.keyboard.press('ControlOrMeta+k');
        await expect(searchField(page)).toBeFocused();
        await page.keyboard.press('Escape');

        await expect(dialog(page)).toHaveCount(0);
        await expect(focusToggle).toHaveAttribute('aria-pressed', 'true');
    });

    test('A partial result says that the index is in progress', async ({ page, api }) => {
        const review = reviewWithHits(api, [TAX_RATE_FILE], true);
        await openFile(page, review, SMALL_FILE);

        await page.getByRole('button', { name: 'Quick open' }).click();
        await search(page, 'tax', NESTED_FILE);

        await expect(dialog(page).getByRole('status')).toHaveText('Index in progress');
    });
});
