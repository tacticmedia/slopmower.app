import type { Symbol as CodeSymbol } from '../api/types/symbol';
import type { LineRange } from './lineHash';

/**
 * The symbol whose range, or whose declaration line through its last line, equals the selection. Null when no
 * symbol or several symbols match, for example the properties of `public int $a, $b;`, and when the FQN of the
 * symbol is not unique.
 */
export function matchingSymbol(symbols: readonly CodeSymbol[], selection: LineRange): CodeSymbol | null {
    const [match, ...others] = symbols.filter(
        ({ range, declarationStartLine }) => selection.end === range.endLine && (selection.start === range.startLine || selection.start === declarationStartLine),
    );

    return undefined !== match && 0 === others.length && !duplicateFqns(symbols).has(match.fqn) ? match : null;
}

/**
 * Q2 rejects these FQNs as ambiguous, for example two conditional declarations of one function.
 */
export function duplicateFqns(symbols: readonly CodeSymbol[]): ReadonlySet<string> {
    const seen = new Set<string>();
    const duplicates = new Set<string>();

    for (const { fqn } of symbols) {
        (seen.has(fqn) ? duplicates : seen).add(fqn);
    }

    return duplicates;
}

/** For example "class constant" for `class_constant`. */
export function symbolKindLabel({ kind }: Pick<CodeSymbol, 'kind'>): string {
    return kind.replaceAll('_', ' ');
}

/** The name as the FQN ends: `total()`, `$taxRate`, `SCALE`. */
export function symbolShortName({ kind, name }: CodeSymbol): string {
    switch (kind) {
        case 'method':
        case 'function':
            return `${name}()`;
        case 'property':
            return `$${name}`;
        default:
            return name;
    }
}
