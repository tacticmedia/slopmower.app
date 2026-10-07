import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { SYMBOL_KINDS } from '../../assets/api/types/common.ts';
import { extract, type SymbolTable } from './extract.ts';
import { type LoadedLanguage, loadLanguages } from './languages.ts';

type Row = [kind: string, fqn: string, name: string, parentFqn: string | null, start: number, end: number, declarationStartLine: number, docStartLine: number | null];

const languages = await loadLanguages();

function language(id: string): LoadedLanguage {
    const loaded = languages.get(id);
    if (undefined === loaded) {
        throw new Error(`No language ${id}.`);
    }

    return loaded;
}

function rows({ symbols }: SymbolTable): Row[] {
    return symbols.map((symbol) => [symbol.kind, symbol.fqn, symbol.name, symbol.parentFqn, symbol.startLine, symbol.endLine, symbol.declarationStartLine, symbol.docStartLine]);
}

function fixture(id: string, file: string): Row[] {
    const table = extract(language(id), readFileSync(new URL(`../../tests/Fixtures/${id}/${file}`, import.meta.url), 'utf8'));
    expect(table.parseError).toBe(false);

    return rows(table);
}

describe('ExtractTest', () => {
    it('The JavaScript fixture gives its table', () => {
        expect(fixture('javascript', 'Invoice.js')).toEqual([
            ['class', 'InvoiceTotals', 'InvoiceTotals', null, 3, 29, 6, 3],
            ['property', 'InvoiceTotals.taxRate', 'taxRate', 'InvoiceTotals', 7, 8, 8, 7],
            ['property', 'InvoiceTotals.#lines', '#lines', 'InvoiceTotals', 10, 10, 10, null],
            ['method', 'InvoiceTotals.fromLines()', 'fromLines', 'InvoiceTotals', 12, 17, 12, null],
            ['method', 'InvoiceTotals.total()', 'total', 'InvoiceTotals', 19, 28, 22, 19],
            ['constant', 'CURRENCY', 'CURRENCY', null, 31, 31, 31, null],
            ['function', 'formatTotal()', 'formatTotal', null, 33, 33, 33, null],
            ['function', 'lineNumbers()', 'lineNumbers', null, 35, 37, 35, null],
        ]);
    });

    it('The JSX fixture gives its table', () => {
        expect(fixture('jsx', 'InvoiceView.jsx')).toEqual([
            ['function', 'InvoiceLine()', 'InvoiceLine', null, 3, 10, 6, 3],
            ['function', 'InvoiceList()', 'InvoiceList', null, 12, 18, 12, null],
            ['class', 'InvoiceView', 'InvoiceView', null, 20, 24, 20, null],
            ['method', 'InvoiceView.render()', 'render', 'InvoiceView', 21, 23, 21, null],
        ]);
    });

    it('The TypeScript fixture with satisfies, decorators, using, and const type parameters gives its table', () => {
        expect(fixture('typescript', 'Invoice.ts')).toEqual([
            ['interface', 'Line', 'Line', null, 3, 7, 3, null],
            ['property', 'Line.amount', 'amount', 'Line', 4, 5, 5, 4],
            ['method', 'Line.label()', 'label', 'Line', 6, 6, 6, null],
            ['type', 'Currency', 'Currency', null, 9, 9, 9, null],
            ['enum', 'Status', 'Status', null, 11, 14, 11, null],
            ['enum_case', 'Status.Draft', 'Draft', 'Status', 12, 12, 12, null],
            ['enum_case', 'Status.Paid', 'Paid', 'Status', 13, 13, 13, null],
            ['class', 'InvoiceTotals', 'InvoiceTotals', null, 16, 40, 19, 16],
            ['property', 'InvoiceTotals.lines', 'lines', 'InvoiceTotals', 21, 21, 21, null],
            ['method', 'InvoiceTotals.constructor()', 'constructor', 'InvoiceTotals', 23, 25, 23, null],
            ['method', 'InvoiceTotals.total()', 'total', 'InvoiceTotals', 27, 35, 27, null],
            ['method', 'InvoiceTotals.clear()', 'clear', 'InvoiceTotals', 37, 39, 38, 37],
            ['module', 'Defaults', 'Defaults', null, 42, 48, 42, null],
            ['constant', 'Defaults.CURRENCY', 'CURRENCY', 'Defaults', 43, 43, 43, null],
            ['function', 'Defaults.rate()', 'rate', 'Defaults', 45, 47, 45, null],
            ['function', 'openScope()', 'openScope', null, 50, 50, 50, null],
        ]);
    });

    it('The TSX fixture gives its table', () => {
        expect(fixture('tsx', 'InvoiceView.tsx')).toEqual([
            ['type', 'Props', 'Props', null, 3, 3, 3, null],
            ['function', 'InvoiceView()', 'InvoiceView', null, 5, 15, 8, 5],
            ['function', 'Empty()', 'Empty', null, 17, 17, 17, null],
        ]);
    });

    it('A syntax error gives a parse error and no symbols', () => {
        expect(extract(language('typescript'), 'export class InvoiceTotals {\n    total(): number {\n')).toEqual({ parseError: true, symbols: [] });
    });

    it('A parse over the time budget gives a parse error, and the next parse starts from the beginning', () => {
        const source = 'export const LINE = 1;\n'.repeat(20_000);

        expect(extract(language('javascript'), source, 0)).toEqual({ parseError: true, symbols: [] });
        expect(rows(extract(language('javascript'), 'export const LINE = 1;\n'))).toEqual([['constant', 'LINE', 'LINE', null, 1, 1, 1, null]]);
    });

    it('A function in a function body is skipped', () => {
        const source = 'function outer() {\n    function inner() {}\n    const callback = () => {};\n}\n';

        expect(rows(extract(language('javascript'), source))).toEqual([['function', 'outer()', 'outer', null, 1, 4, 1, null]]);
    });

    it('A doc comment before export and before decorators starts the range', () => {
        const exported = '/**\n * Rounds.\n */\nexport function round() {}\n';
        const decorated = 'class Invoice {\n    /** Logs. */\n    @logged\n    @traced\n    clear(): void {}\n}\n';

        expect(rows(extract(language('javascript'), exported))).toEqual([['function', 'round()', 'round', null, 1, 4, 4, 1]]);
        expect(rows(extract(language('typescript'), decorated))[1]).toEqual(['method', 'Invoice.clear()', 'clear', 'Invoice', 2, 5, 3, 2]);
    });

    it('Overload signatures join the range of the implementation', () => {
        const source = [
            '/** Parses. */',
            'export function parse(text: string): number;',
            'export function parse(text: null): null;',
            'export function parse(text: string | null): number | null {',
            '    return null === text ? null : Number(text);',
            '}',
            'export function parse(): void {}',
            '',
        ].join('\n');

        expect(rows(extract(language('typescript'), source))).toEqual([
            ['function', 'parse()', 'parse', null, 1, 6, 2, 1],
            ['function', 'parse()', 'parse', null, 7, 7, 7, null],
        ]);
    });

    it('Every kind that a query captures is a SymbolKind of the contract', () => {
        const kinds = [...languages.values()].flatMap(({ query }) => query.captureNames.filter((name) => 'name' !== name));

        expect(kinds.filter((kind) => !(SYMBOL_KINDS as readonly string[]).includes(kind))).toEqual([]);
    });
});
