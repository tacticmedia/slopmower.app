import type { Node, QueryMatch } from 'web-tree-sitter';
import type { LanguageRow, LoadedLanguage } from './languages.ts';

/** symbol.schema.json, with the range flattened. */
export type ExtractedSymbol = {
    kind: string;
    fqn: string;
    name: string;
    parentFqn: string | null;
    startLine: number;
    endLine: number;
    declarationStartLine: number;
    docStartLine: number | null;
};

export type SymbolTable = { parseError: boolean; symbols: ExtractedSymbol[] };

export const PARSE_BUDGET_MILLISECONDS = 2000;

const CONTAINERS: ReadonlySet<string> = new Set(['class', 'interface', 'trait', 'enum', 'struct', 'module']);

const MEMBERS: ReadonlySet<string> = new Set(['method', 'property', 'class_constant', 'enum_case']);

const CALLABLES: ReadonlySet<string> = new Set(['function', 'method']);

const SUFFIXES: Readonly<Record<string, string>> = { function: '()', method: '()', macro: '!' };

/** `node` carries the kind capture, `outer` is its outermost wrapper. */
type Declaration = { kind: string; name: string; node: Node; outer: Node; pattern: number };

type Found = { symbol: ExtractedSymbol; declaration: Declaration };

/**
 * A tree with an error, or a parse over the budget, gives a parse error and no symbols. Symbols are in document order.
 */
export function extract({ row, parser, query }: LoadedLanguage, source: string, budgetMilliseconds = PARSE_BUDGET_MILLISECONDS): SymbolTable {
    const deadline = performance.now() + budgetMilliseconds;
    const tree = parser.parse(source, null, { progressCallback: () => performance.now() > deadline });

    if (null === tree) {
        // A cancelled parse would resume at the next call.
        parser.reset();

        return { parseError: true, symbols: [] };
    }

    try {
        return tree.rootNode.hasError ? { parseError: true, symbols: [] } : { parseError: false, symbols: symbols(row, query.matches(tree.rootNode)) };
    } finally {
        tree.delete();
    }
}

function symbols(row: LanguageRow, matches: QueryMatch[]): ExtractedSymbol[] {
    const containers = new Map<number, ExtractedSymbol>();
    const found: Found[] = [];

    for (const declaration of declarations(row, matches)) {
        const parent = enclosingContainer(row, declaration.outer, containers);

        if (undefined === parent || (MEMBERS.has(declaration.kind) && null === parent)) {
            continue;
        }

        const symbol = toSymbol(row, declaration, parent);
        const previous = found.at(-1);

        if (undefined !== previous && isOverload(previous, symbol, declaration)) {
            found.pop();
            symbol.declarationStartLine = previous.symbol.declarationStartLine;
            symbol.docStartLine = previous.symbol.docStartLine;
            symbol.startLine = previous.symbol.startLine;
        }

        found.push({ symbol, declaration });

        if (CONTAINERS.has(declaration.kind)) {
            containers.set(declaration.node.id, symbol);
        }
    }

    return found.map(({ symbol }) => symbol);
}

/**
 * One declaration per name node, in document order.
 */
function declarations(row: LanguageRow, matches: QueryMatch[]): Declaration[] {
    const byNameNode = new Map<number, Declaration>();

    for (const { patternIndex, captures } of matches) {
        const name = captures.find((capture) => 'name' === capture.name)?.node;
        const kind = captures.find((capture) => 'name' !== capture.name);
        const known = undefined === name ? undefined : byNameNode.get(name.id);

        if (undefined !== name && undefined !== kind && (undefined === known || patternIndex < known.pattern)) {
            byNameNode.set(name.id, { kind: kind.name, name: name.text, node: kind.node, outer: outermost(row, kind.node), pattern: patternIndex });
        }
    }

    return [...byNameNode.values()].sort((first, second) => first.outer.startIndex - second.outer.startIndex || first.node.startIndex - second.node.startIndex);
}

function outermost(row: LanguageRow, node: Node): Node {
    let outer = node;

    while (null !== outer.parent && row.wrappers.includes(outer.parent.type)) {
        outer = outer.parent;
    }

    return outer;
}

/**
 * @returns Null at the top level, undefined inside an opaque node
 */
function enclosingContainer(row: LanguageRow, outer: Node, containers: ReadonlyMap<number, ExtractedSymbol>): ExtractedSymbol | null | undefined {
    for (let node = outer.parent; null !== node; node = node.parent) {
        if (row.opaque.includes(node.type)) {
            return undefined;
        }

        const container = containers.get(node.id);

        if (undefined !== container) {
            return container;
        }
    }

    return null;
}

/**
 * D7: the range starts at the doc comment. A node that ends at column 0 ends on the line before.
 */
function toSymbol(row: LanguageRow, { kind, name, outer }: Declaration, parent: ExtractedSymbol | null): ExtractedSymbol {
    let first = outer;

    while (null !== first.previousNamedSibling && row.attributes.includes(first.previousNamedSibling.type)) {
        first = first.previousNamedSibling;
    }

    const declarationStartLine = first.startPosition.row + 1;
    const docStartLine = docCommentStartLine(row, first);
    const { row: endRow, column: endColumn } = outer.endPosition;

    return {
        kind,
        fqn: `${null === parent ? '' : `${parent.fqn}${row.separator}`}${name}${SUFFIXES[kind] ?? ''}`,
        name,
        parentFqn: parent?.fqn ?? null,
        startLine: docStartLine ?? declarationStartLine,
        endLine: 0 === endColumn && endRow > outer.startPosition.row ? endRow : endRow + 1,
        declarationStartLine,
        docStartLine,
    };
}

/**
 * The comment right before the declaration, and the comments that precede it line by line.
 */
function docCommentStartLine(row: LanguageRow, first: Node): number | null {
    let doc: Node | null = null;

    for (let sibling = first.previousNamedSibling; null !== sibling && row.docComment.test(sibling.text); sibling = sibling.previousNamedSibling) {
        if (null !== doc && sibling.endPosition.row + 1 < doc.startPosition.row) {
            break;
        }

        doc = sibling;
    }

    return null === doc ? null : doc.startPosition.row + 1;
}

/**
 * A TypeScript overload signature: a function or method without a body, right before a declaration with its FQN.
 */
function isOverload(previous: Found, symbol: ExtractedSymbol, declaration: Declaration): boolean {
    return (
        previous.symbol.fqn === symbol.fqn &&
        previous.symbol.kind === symbol.kind &&
        CALLABLES.has(symbol.kind) &&
        null === previous.declaration.node.childForFieldName('body') &&
        previous.declaration.outer.parent?.id === declaration.outer.parent?.id
    );
}
