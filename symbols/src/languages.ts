import { readFile } from 'node:fs/promises';
import { Language, Parser, Query } from 'web-tree-sitter';

/** A language of the service: a grammar, a query file, and the rules that the query cannot express. */
export type LanguageRow = {
    /** A `.wasm` file under `node_modules`. */
    grammar: string;
    /** `queries/<query>.scm`. Its captures are `SymbolKind` values and `@name`. */
    query: string;
    /** Joins a parent FQN and a name. */
    separator: string;
    /** Node types that wrap a declaration. The range of the declaration starts with the outermost wrapper. */
    wrappers: readonly string[];
    /** Node types of preceding siblings that belong to the declaration, such as decorators. */
    attributes: readonly string[];
    /** Node types whose descendants are no symbols: function bodies and anonymous classes. */
    opaque: readonly string[];
    /** A preceding comment that matches starts the range. */
    docComment: RegExp;
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
};

/** A top-level namespace is an expression statement. Method decorators are siblings of the method. */
const TYPESCRIPT: Omit<LanguageRow, 'grammar'> = {
    query: 'typescript',
    separator: '.',
    wrappers: ['export_statement', 'ambient_declaration', 'expression_statement'],
    attributes: ['decorator'],
    opaque: JAVASCRIPT_OPAQUE,
    docComment: JSDOC,
};

/** By V7 language. `TreeSitterSymbolExtractor::LANGUAGES` lists the same keys. */
export const LANGUAGES: Readonly<Record<string, LanguageRow>> = {
    javascript: { ...JAVASCRIPT, grammar: 'tree-sitter-javascript/tree-sitter-javascript.wasm' },
    jsx: { ...JAVASCRIPT, grammar: 'tree-sitter-javascript/tree-sitter-javascript.wasm' },
    typescript: { ...TYPESCRIPT, grammar: 'tree-sitter-typescript/tree-sitter-typescript.wasm' },
    tsx: { ...TYPESCRIPT, grammar: 'tree-sitter-typescript/tree-sitter-tsx.wasm' },
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
