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

/** Captures that are no kind: the name, the parent inside the enclosing container, and the trait of a Rust trait impl. */
export const ROLES: ReadonlySet<string> = new Set(['name', 'parent', 'as']);

/** `node` carries the kind capture, `outer` is its outermost wrapper. `parent` and `as` are capture texts. */
type Declaration = { kind: string; name: string; node: Node; outer: Node; pattern: number; parent: string | null; as: string | null };

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
        const container = enclosingContainer(row, declaration.outer, containers);

        if (undefined === container) {
            continue;
        }

        const containerFqn = container?.fqn ?? null;
        const parentFqn = null === declaration.parent ? containerFqn : joined(row, containerFqn, declaration.parent);

        if (MEMBERS.has(declaration.kind) && null === parentFqn) {
            continue;
        }

        const symbol = toSymbol(row, declaration, parentFqn);
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
        const kind = captures.find((capture) => !ROLES.has(capture.name));
        const known = undefined === name ? undefined : byNameNode.get(name.id);

        if (undefined !== name && undefined !== kind && (undefined === known || patternIndex < known.pattern)) {
            byNameNode.set(name.id, {
                kind: kind.name,
                name: name.text,
                node: kind.node,
                outer: outermost(row, kind.node),
                pattern: patternIndex,
                parent: captureText(captures, 'parent'),
                as: captureText(captures, 'as'),
            });
        }
    }

    return [...byNameNode.values()].sort((first, second) => first.outer.startIndex - second.outer.startIndex || first.node.startIndex - second.node.startIndex);
}

/** Whitespace runs become one space, so the FQN does not depend on the line breaks of a type. */
function captureText(captures: QueryMatch['captures'], name: string): string | null {
    return captures.find((capture) => name === capture.name)?.node.text.replace(/\s+/g, ' ') ?? null;
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

function joined(row: LanguageRow, parentFqn: string | null, name: string): string {
    return null === parentFqn ? name : `${parentFqn}${row.separator}${name}`;
}

/**
 * D7: the range starts at the doc comment. A node that ends at column 0 ends on the line before. A member of a
 * trait impl is `<parent as trait>::name`.
 */
function toSymbol(row: LanguageRow, { kind, name, outer, as }: Declaration, parentFqn: string | null): ExtractedSymbol {
    let first = outer;

    while (null !== first.previousNamedSibling && row.attributes.includes(first.previousNamedSibling.type)) {
        first = first.previousNamedSibling;
    }

    const declarationStartLine = first.startPosition.row + 1;
    const docStartLine = docCommentStartLine(row, first);
    const { row: endRow, column: endColumn } = outer.endPosition;

    return {
        kind,
        fqn: joined(row, null === as || null === parentFqn ? parentFqn : `<${parentFqn} as ${as}>`, `${name}${SUFFIXES[kind] ?? ''}`),
        name,
        parentFqn,
        startLine: docStartLine ?? declarationStartLine,
        endLine: 0 === endColumn && endRow > outer.startPosition.row ? endRow : endRow + 1,
        declarationStartLine,
        docStartLine,
    };
}

/**
 * The comment right before the declaration, and the comments that precede it line by line.
 */
function docCommentStartLine({ docComment, docCommentAdjacent }: LanguageRow, first: Node): number | null {
    if (null === docComment) {
        return null;
    }

    let doc: Node | null = null;

    for (let sibling = previousNamed(first); null !== sibling && docComment.test(sibling.text); sibling = sibling.previousNamedSibling) {
        const below = doc ?? first;

        if ((null !== doc || docCommentAdjacent) && sibling.endPosition.row + 1 < below.startPosition.row) {
            break;
        }

        doc = sibling;
    }

    return null === doc ? null : doc.startPosition.row + 1;
}

/**
 * A parent that starts with the node passes the search on to its own previous sibling: a Ruby body starts at its first
 * statement, after the comments before it.
 */
function previousNamed(node: Node): Node | null {
    let current = node;

    while (null === current.previousNamedSibling && null !== current.parent && current.parent.startIndex === current.startIndex) {
        current = current.parent;
    }

    return current.previousNamedSibling;
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
