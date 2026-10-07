import { readFile } from 'node:fs/promises';
import { Language, Parser, Query } from 'web-tree-sitter';

/** A language of the service: a grammar, a query file, and the rules that the query cannot express. */
export type LanguageRow = {
    /** A `.wasm` file under `node_modules`. */
    grammar: string;
    /**
     * `queries/<query>.scm`. Its captures are `SymbolKind` values and `@name`. `@parent` names the parent inside the
     * enclosing container, and `@as` the trait of a Rust trait impl.
     */
    query: string;
    /** Joins a parent FQN and a name. */
    separator: string;
    /** Node types that wrap a declaration. The range of the declaration starts with the outermost wrapper. */
    wrappers: readonly string[];
    /** Node types of preceding siblings that belong to the declaration, such as decorators. */
    attributes: readonly string[];
    /** Node types whose descendants are no symbols: function bodies and anonymous classes. */
    opaque: readonly string[];
    /** A preceding comment that matches starts the range. Null when the language has no doc comment before it. */
    docComment: RegExp | null;
    /** A blank line before the declaration ends the doc comment. */
    docCommentAdjacent: boolean;
};

export type LoadedLanguage = { row: LanguageRow; parser: Parser; query: Query };

// `/**`, but not the empty comment `/**/`.
const JSDOC = /^\/\*\*(?!\/)/;

const JAVASCRIPT_OPAQUE = [
    'function_declaration',
    'generator_function_declaration',
    'function_expression',
    'generator_function',
    'arrow_function',
    'method_definition',
    'class_static_block',
    'class',
];

const JAVASCRIPT: Omit<LanguageRow, 'grammar'> = {
    query: 'javascript',
    separator: '.',
    wrappers: ['export_statement'],
    attributes: [],
    opaque: JAVASCRIPT_OPAQUE,
    docComment: JSDOC,
    docCommentAdjacent: false,
};

/** A top-level namespace is an expression statement. Method decorators are siblings of the method. */
const TYPESCRIPT: Omit<LanguageRow, 'grammar'> = {
    query: 'typescript',
    separator: '.',
    wrappers: ['export_statement', 'ambient_declaration', 'expression_statement'],
    attributes: ['decorator'],
    opaque: JAVASCRIPT_OPAQUE,
    docComment: JSDOC,
    docCommentAdjacent: false,
};

/** The docstring is inside the range. */
const PYTHON: LanguageRow = {
    grammar: 'tree-sitter-python/tree-sitter-python.wasm',
    query: 'python',
    separator: '.',
    wrappers: ['decorated_definition'],
    attributes: [],
    opaque: ['function_definition'],
    docComment: null,
    docCommentAdjacent: false,
};

/** A field of an anonymous struct type is no property of the enclosing struct. */
const GO: LanguageRow = {
    grammar: 'tree-sitter-go/tree-sitter-go.wasm',
    query: 'go',
    separator: '.',
    wrappers: [],
    attributes: [],
    opaque: ['function_declaration', 'method_declaration', 'func_literal', 'field_declaration'],
    docComment: /^\/[/*]/,
    docCommentAdjacent: true,
};

// An item in a block is local to the block. A doc comment is `///` or a block comment that opens with `/**`, but not
// `////`, `/***`, or the empty `/**/`.
const RUST: LanguageRow = {
    grammar: 'tree-sitter-rust/tree-sitter-rust.wasm',
    query: 'rust',
    separator: '::',
    wrappers: [],
    attributes: ['attribute_item'],
    opaque: ['block'],
    docComment: /^(\/\/\/(?!\/)|\/\*\*(?![*/]))/,
    docCommentAdjacent: false,
};

/** By V7 language. `TreeSitterSymbolExtractor::LANGUAGES` lists the same keys. */
export const LANGUAGES: Readonly<Record<string, LanguageRow>> = {
    javascript: { ...JAVASCRIPT, grammar: 'tree-sitter-javascript/tree-sitter-javascript.wasm' },
    jsx: { ...JAVASCRIPT, grammar: 'tree-sitter-javascript/tree-sitter-javascript.wasm' },
    typescript: { ...TYPESCRIPT, grammar: 'tree-sitter-typescript/tree-sitter-typescript.wasm' },
    tsx: { ...TYPESCRIPT, grammar: 'tree-sitter-typescript/tree-sitter-tsx.wasm' },
    python: PYTHON,
    go: GO,
    rust: RUST,
};

export async function loadLanguages(): Promise<Map<string, LoadedLanguage>> {
    await Parser.init();
    const loaded = new Map<string, LoadedLanguage>();

    for (const [id, row] of Object.entries(LANGUAGES)) {
        const language = await Language.load(new URL(`../node_modules/${row.grammar}`, import.meta.url));
        const source = await readFile(new URL(`../queries/${row.query}.scm`, import.meta.url), 'utf8');
        loaded.set(id, { row, parser: new Parser().setLanguage(language), query: new Query(language, source) });
    }

    return loaded;
}
