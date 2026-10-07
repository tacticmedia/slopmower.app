import { describe, expect, it } from 'vitest';
import type { Symbol as CodeSymbol } from '../api/types/symbol';
import { MAX_SHOWN_SYMBOLS, symbolItems, TOP_LEVEL_GROUP } from './symbolItems';

function symbol(kind: string, fqn: string, name: string, parentFqn: string | null, startLine: number): CodeSymbol {
    return { kind, fqn, name, parentFqn, range: { startLine, endLine: startLine }, declarationStartLine: startLine, docStartLine: null };
}

const CLASS = symbol('class', 'App\\Billing\\TaxRounding', 'TaxRounding', null, 7);
const ROUND = symbol('method', 'App\\Billing\\TaxRounding::round()', 'round', 'App\\Billing\\TaxRounding', 14);
const HELPER = symbol('function', 'App\\Billing\\round_cents()', 'round_cents', null, 30);

describe('SymbolItemsTest', () => {
    it('Groups the short names that contain the filter by parent, and a class-like heads its group', () => {
        expect(symbolItems([CLASS, ROUND, HELPER], 'ROUND').groups).toEqual([
            { id: CLASS.fqn, symbols: [CLASS, ROUND] },
            { id: TOP_LEVEL_GROUP, symbols: [HELPER] },
        ]);
        expect(symbolItems([CLASS, ROUND, HELPER], 'round(').groups).toEqual([{ id: CLASS.fqn, symbols: [ROUND] }]);
    });

    it('A struct and a module head their groups', () => {
        const module = symbol('module', 'billing', 'billing', null, 1);
        const round = symbol('function', 'billing::round()', 'round', 'billing', 2);
        const line = symbol('struct', 'Line', 'Line', null, 10);
        const amount = symbol('property', 'Line::amount', 'amount', 'Line', 11);

        expect(symbolItems([module, round, line, amount], '').groups).toEqual([
            { id: 'billing', symbols: [module, round] },
            { id: 'Line', symbols: [line, amount] },
        ]);
    });

    it('A class in a namespace heads its own group', () => {
        const namespace = symbol('module', 'Billing', 'Billing', null, 1);
        const invoice = symbol('class', 'Billing.Invoice', 'Invoice', 'Billing', 2);
        const total = symbol('method', 'Billing.Invoice.total()', 'total', 'Billing.Invoice', 3);

        expect(symbolItems([namespace, invoice, total], '').groups).toEqual([
            { id: 'Billing', symbols: [namespace] },
            { id: 'Billing.Invoice', symbols: [invoice, total] },
        ]);
    });

    it('Shows at most the first matches and counts all of them', () => {
        const constants = Array.from({ length: MAX_SHOWN_SYMBOLS + 5 }, (_, index) => symbol('constant', `LINE_${index}`, `LINE_${index}`, null, index + 1));

        const { groups, shownCount, matchCount } = symbolItems(constants, '');

        expect([groups[0]?.symbols.length, shownCount, matchCount]).toEqual([MAX_SHOWN_SYMBOLS, MAX_SHOWN_SYMBOLS, MAX_SHOWN_SYMBOLS + 5]);
    });
});
