import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { SYMBOL_KINDS } from '../../assets/api/types/common.ts';
import { extract, ROLES, type SymbolTable } from './extract.ts';
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

    it('The Python fixture with decorators, a nested class, and a function in a method gives its table', () => {
        expect(fixture('python', 'invoice.py')).toEqual([
            ['class', 'Line', 'Line', null, 7, 18, 7, null],
            ['class', 'Line.Meta', 'Meta', 'Line', 14, 18, 14, null],
            ['method', 'Line.Meta.describe()', 'describe', 'Line.Meta', 17, 18, 17, null],
            ['class', 'InvoiceTotals', 'InvoiceTotals', null, 21, 38, 21, null],
            ['method', 'InvoiceTotals.__init__()', '__init__', 'InvoiceTotals', 22, 23, 22, null],
            ['method', 'InvoiceTotals.total()', 'total', 'InvoiceTotals', 25, 34, 25, null],
            ['method', 'InvoiceTotals.load()', 'load', 'InvoiceTotals', 36, 38, 36, null],
            ['function', 'format_total()', 'format_total', null, 41, 42, 41, null],
        ]);
    });

    it('The Go fixture with a receiver type of another file, pointer and generic receivers, and doc comment blocks gives its table', () => {
        expect(fixture('go', 'totals.go')).toEqual([
            ['type', 'Currency', 'Currency', null, 9, 10, 10, 9],
            ['constant', 'Scale', 'Scale', null, 13, 14, 14, 13],
            ['constant', 'Rounding', 'Rounding', null, 15, 15, 15, null],
            ['struct', 'Totals', 'Totals', null, 18, 24, 20, 18],
            ['property', 'Totals.Lines', 'Lines', 'Totals', 21, 22, 22, 21],
            ['property', 'Totals.Currency', 'Currency', 'Totals', 23, 23, 23, null],
            ['interface', 'Amount', 'Amount', null, 26, 29, 27, 26],
            ['method', 'Amount.Cents()', 'Cents', 'Amount', 28, 28, 28, null],
            ['method', 'Totals.Sum()', 'Sum', 'Totals', 31, 41, 32, 31],
            ['method', 'Totals.Count()', 'Count', 'Totals', 43, 43, 43, null],
            ['method', 'Line.Cents()', 'Cents', 'Line', 47, 47, 47, null],
            ['function', 'Format()', 'Format', null, 49, 52, 49, null],
        ]);
    });

    it('The Rust fixture with impls, two From impls, nested modules, a macro, and attributes after a doc comment gives its table', () => {
        expect(fixture('rust', 'totals.rs')).toEqual([
            ['constant', 'SCALE', 'SCALE', null, 5, 6, 6, 5],
            ['struct', 'Total', 'Total', null, 8, 15, 9, 8],
            ['property', 'Total::cents', 'cents', 'Total', 12, 13, 13, 12],
            ['property', 'Total::currency', 'currency', 'Total', 14, 14, 14, null],
            ['enum', 'Currency', 'Currency', null, 17, 21, 17, null],
            ['enum_case', 'Currency::Aud', 'Aud', 'Currency', 19, 19, 19, null],
            ['enum_case', 'Currency::Eur', 'Eur', 'Currency', 20, 20, 20, null],
            ['constant', 'Total::ZERO', 'ZERO', 'Total', 24, 24, 24, null],
            ['method', 'Total::new()', 'new', 'Total', 26, 31, 26, null],
            ['method', '<Total as From<i64>>::from()', 'from', 'Total', 35, 37, 35, null],
            ['method', '<Total as From<u32>>::from()', 'from', 'Total', 41, 43, 41, null],
            ['type', '<Total as std::ops::Add<i64>>::Output', 'Output', 'Total', 47, 47, 47, null],
            ['method', '<Total as std::ops::Add<i64>>::add()', 'add', 'Total', 49, 51, 49, null],
            ['method', '<Total as fmt::Display>::fmt()', 'fmt', 'Total', 55, 57, 55, null],
            ['trait', 'Rounding', 'Rounding', null, 60, 62, 60, null],
            ['method', 'Rounding::round()', 'round', 'Rounding', 61, 61, 61, null],
            ['macro', 'total!', 'total', null, 64, 68, 64, null],
            ['module', 'billing', 'billing', null, 70, 80, 70, null],
            ['module', 'billing::tax', 'tax', 'billing', 71, 79, 71, null],
            ['struct', 'billing::tax::Rate', 'Rate', 'billing::tax', 72, 72, 72, null],
            ['method', 'billing::tax::Rate::value()', 'value', 'billing::tax::Rate', 75, 77, 75, null],
        ]);
    });

    it('Line breaks in the type and trait of an impl become one space', () => {
        const source = 'impl<T>\n    From<\n        Vec<T>,\n    > for &\'static\n    [T]\n{\n    fn from() {}\n}\n';

        expect(rows(extract(language('rust'), source))).toEqual([['method', "<&'static [T] as From< Vec<T>, >>::from()", 'from', "&'static [T]", 7, 7, 7, null]]);
    });

    it('A syntax error gives a parse error and no symbols', () => {
        const broken: Record<string, string> = {
            typescript: 'export class InvoiceTotals {\n    total(): number {\n',
            python: 'class InvoiceTotals:\n    def total(self) -> int:\n        return (\n',
            go: 'package billing\n\nfunc Total() int {\n',
            rust: 'impl Total {\n    fn new() -> Self {\n',
        };

        for (const [id, source] of Object.entries(broken)) {
            expect(extract(language(id), source)).toEqual({ parseError: true, symbols: [] });
        }
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
        const kinds = [...languages.values()].flatMap(({ query }) => query.captureNames.filter((name) => !ROLES.has(name)));

        expect(kinds.filter((kind) => !(SYMBOL_KINDS as readonly string[]).includes(kind))).toEqual([]);
    });
});
