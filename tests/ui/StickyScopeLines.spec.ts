import { CODE_HOST, location, selectedLines } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { LARGE_FILE } from './support/files';
import { expect, test } from './support/test';

const SCOPE_LINES = '[data-testid="scope-lines"]';

/** The class spans the file. The method `commit()` stands for the lines 100 to 400. */
const SYMBOLS = {
    [LARGE_FILE]: [
        { kind: 'class', fqn: 'UnitOfWork', name: 'UnitOfWork', parentFqn: null, range: { startLine: 2, endLine: 3500 }, declarationStartLine: 2, docStartLine: null },
        { kind: 'method', fqn: 'UnitOfWork::commit()', name: 'commit', parentFqn: 'UnitOfWork', range: { startLine: 100, endLine: 400 }, declarationStartLine: 100, docStartLine: null },
    ],
};

test.describe('StickyScopeLines', () => {
    test('Inside a method, the class and method lines stay below the toolbar, and a click goes to the method', async ({ page, api }) => {
        const review = new FakeReview(api, { symbols: SYMBOLS });

        await page.goto(review.fileUrl(LARGE_FILE, '#L250'));
        await expect(page.locator(`${CODE_HOST} [data-line="250"][data-selected-line]`)).toBeAttached();

        const scopes = page.locator(SCOPE_LINES).getByRole('button');
        await expect(scopes).toHaveText(['2final class UnitOfWork', '100    public const LINE_100 = 100;']);
        await scopes.nth(1).click();

        await expect.poll(() => location(page)).toBe(review.fileUrl(LARGE_FILE, '#L100'));
        await expect.poll(() => selectedLines(page)).toEqual(['100']);
    });

    test('A file without symbols shows no scope lines', async ({ page, api }) => {
        const review = new FakeReview(api, { symbols: {} });

        await page.goto(review.fileUrl(LARGE_FILE, '#L250'));

        await expect(page.locator('[data-testid="stuck-header"]')).toBeVisible();
        await expect(page.locator(SCOPE_LINES)).toHaveCount(0);
    });
});
