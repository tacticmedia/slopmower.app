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

/**
 * The end of the FQN from the last `name`, with a `$` before it: `total()`, `$taxRate`, `SCALE`, `format_cents!`.
 */
export function symbolShortName({ fqn, name }: CodeSymbol): string {
    const start = fqn.lastIndexOf(name);

    if (-1 === start) {
        return name;
    }

    return '$' === fqn[start - 1] ? `$${fqn.slice(start)}` : fqn.slice(start);
}

/** The containers of D57: each heads its group in the Symbols menu. */
export const CLASS_LIKE_KINDS: ReadonlySet<string> = new Set(['class', 'interface', 'trait', 'enum', 'struct', 'module']);

/** The kinds whose declaration line shows as a sticky scope line. */
const SCOPE_KINDS: ReadonlySet<string> = new Set([...CLASS_LIKE_KINDS, 'function', 'method', 'block']);

/**
 * The scopes that enclose `line` below their declaration line, outermost first, as GitHub's sticky lines show them.
 */
export function enclosingScopes(symbols: readonly CodeSymbol[], line: number): CodeSymbol[] {
    return symbols
        .filter(({ kind, declarationStartLine, range }) => SCOPE_KINDS.has(kind) && declarationStartLine < line && line <= range.endLine)
        .sort((first, second) => first.declarationStartLine - second.declarationStartLine);
}
