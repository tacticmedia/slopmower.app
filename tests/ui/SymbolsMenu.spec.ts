import type { Symbol as CodeSymbol } from '../../assets/api/types/symbol';
import { openFile, waitForSymbols } from './support/codeView';
import { FakeReview } from './support/fakeReview';
import { codeViewFiles, codeViewSymbols, SMALL_FILE } from './support/files';
import { apiError } from './support/mockApi';
import { expect, test } from './support/test';

const TYPESCRIPT_FILE = 'assets/billing/invoiceTotals.ts';

const TYPESCRIPT_TEXT = `export class InvoiceTotals {
    total(): number {
        return 0;
    }
}

export function formatCents(cents: number): string {
    return String(cents / 100);
}
`;

function symbol(kind: string, fqn: string, name: string, parentFqn: string | null, startLine: number, endLine: number): CodeSymbol {
    return { kind, fqn, name, parentFqn, range: { startLine, endLine }, declarationStartLine: startLine, docStartLine: null };
}

const TYPESCRIPT_SYMBOLS = [
    symbol('class', 'InvoiceTotals', 'InvoiceTotals', null, 1, 5),
    symbol('method', 'InvoiceTotals.total()', 'total', 'InvoiceTotals', 2, 4),
    symbol('function', 'formatCents()', 'formatCents', null, 7, 9),
];

test.describe('SymbolsMenu', () => {
    test('Symbols says why it is unavailable', async ({ page, api }) => {
        const review = new FakeReview(api);
        const symbols = page.locator('[data-testid="file-header"]').getByRole('button', { name: 'Symbols' });

        await openFile(page, review, 'README.md');
        await expect(symbols).toHaveAccessibleDescription('No symbols in this file');

        api.on('GET', '/api/v1/reviews/:reviewId/symbols', () => apiError(500, 'internal_error', 'The symbol index failed.'));
        await openFile(page, review, SMALL_FILE);
        await expect(symbols).toHaveAccessibleDescription('Symbols could not be loaded');
    });

    test('A TypeScript file lists its symbols by container', async ({ page, api }) => {
        const review = new FakeReview(api, { files: { ...codeViewFiles(), [TYPESCRIPT_FILE]: TYPESCRIPT_TEXT }, symbols: { ...codeViewSymbols(), [TYPESCRIPT_FILE]: TYPESCRIPT_SYMBOLS } });
        await openFile(page, review, TYPESCRIPT_FILE);
        await waitForSymbols(page);

        await page.locator('[data-testid="file-header"]').getByRole('button', { name: 'Symbols' }).click();

        await expect(page.getByRole('option')).toHaveText([/^InvoiceTotals/, /^total\(\)/, /^formatCents\(\)/]);
        await expect(page.getByRole('group', { name: 'InvoiceTotals' }).getByRole('option')).toHaveText([/^InvoiceTotals/, /^total\(\)/]);
        await expect(page.getByRole('group', { name: 'Top level' }).getByRole('option')).toHaveText([/^formatCents\(\)/]);
    });
});
