import type { Symbol as CodeSymbol } from '../api/types/symbol';
import { CLASS_LIKE_KINDS, symbolShortName } from '../code/symbolMatch';

/** More options make the menu slow to open. The filter reaches the rest. */
export const MAX_SHOWN_SYMBOLS = 200;

/** The group of the functions and constants outside a class-like. */
export const TOP_LEVEL_GROUP = '';

export type SymbolGroup = { id: string; symbols: CodeSymbol[] };

export type SymbolItems = {
    /** In the V9 order of their first symbol. */
    groups: SymbolGroup[];
    shownCount: number;
    matchCount: number;
};

/**
 * The symbols whose short name contains the filter, ignoring case, grouped by parent. A class-like heads its own
 * group.
 */
export function symbolItems(symbols: readonly CodeSymbol[], filter: string): SymbolItems {
    const needle = filter.trim().toLowerCase();
    const matches = symbols.filter((symbol) => symbolShortName(symbol).toLowerCase().includes(needle));
    const groups = new Map<string, CodeSymbol[]>();

    for (const symbol of matches.slice(0, MAX_SHOWN_SYMBOLS)) {
        const id = CLASS_LIKE_KINDS.has(symbol.kind) ? symbol.fqn : (symbol.parentFqn ?? TOP_LEVEL_GROUP);
        const group = groups.get(id);

        if (undefined === group) {
            groups.set(id, [symbol]);
        } else {
            group.push(symbol);
        }
    }

    return {
        groups: [...groups].map(([id, grouped]) => ({ id, symbols: grouped })),
        shownCount: Math.min(matches.length, MAX_SHOWN_SYMBOLS),
        matchCount: matches.length,
    };
}
