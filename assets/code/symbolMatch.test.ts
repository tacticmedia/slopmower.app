import { describe, expect, it } from 'vitest';
import type { Symbol as CodeSymbol } from '../api/types/symbol';
import { matchingSymbol } from './symbolMatch';

const TOTAL: CodeSymbol = {
    kind: 'method',
    fqn: 'App\\Billing\\InvoiceTotals::total()',
    name: 'total',
    parentFqn: 'App\\Billing\\InvoiceTotals',
    range: { startLine: 38, endLine: 49 },
    declarationStartLine: 41,
    docStartLine: 38,
};

const CLASS: CodeSymbol = {
    kind: 'class',
    fqn: 'App\\Billing\\InvoiceTotals',
    name: 'InvoiceTotals',
    parentFqn: null,
    range: { startLine: 9, endLine: 74 },
    declarationStartLine: 9,
    docStartLine: null,
};

const SYMBOLS = [CLASS, TOTAL];

describe('SymbolMatchTest', () => {
    it('A selection of the range with the doc comment matches the symbol', () => {
        expect(matchingSymbol(SYMBOLS, { start: 38, end: 49 })).toBe(TOTAL);
    });

    it('A selection from the declaration line to the end matches the symbol', () => {
        expect(matchingSymbol(SYMBOLS, { start: 41, end: 49 })).toBe(TOTAL);
    });

    it('A selection one line off stays a range', () => {
        expect([
            matchingSymbol(SYMBOLS, { start: 37, end: 49 }),
            matchingSymbol(SYMBOLS, { start: 39, end: 49 }),
            matchingSymbol(SYMBOLS, { start: 41, end: 48 }),
            matchingSymbol(SYMBOLS, { start: 38, end: 50 }),
        ]).toEqual([null, null, null, null]);
    });

    it('A selection of a symbol whose FQN occurs twice stays a range', () => {
        const windows: CodeSymbol = { ...CLASS, kind: 'function', fqn: 'home()', name: 'home', range: { startLine: 4, endLine: 4 }, declarationStartLine: 4 };
        const other: CodeSymbol = { ...windows, range: { startLine: 6, endLine: 6 }, declarationStartLine: 6 };

        expect(matchingSymbol([windows, other], { start: 4, end: 4 })).toBeNull();
    });

    it('A selection that two symbols share stays a range', () => {
        const first: CodeSymbol = { ...TOTAL, kind: 'property', fqn: 'App\\Billing\\InvoiceTotals::$a', name: 'a', range: { startLine: 12, endLine: 12 }, declarationStartLine: 12, docStartLine: null };

        expect(matchingSymbol([first, { ...first, fqn: 'App\\Billing\\InvoiceTotals::$b', name: 'b' }], { start: 12, end: 12 })).toBeNull();
    });
});
