import { extract } from '../../symbols/src/extract.ts';
import { BROWSER_LANGUAGES, type LanguageFiles, type LoadedLanguage, loadLanguage } from '../../symbols/src/languages.ts';
import runtime from '../../symbols/node_modules/web-tree-sitter/web-tree-sitter.wasm?url';
import type { SymbolKind } from '../api/types/common';
import type { Symbol as CodeSymbol } from '../api/types/symbol';

/** The outline of Browser only (D114): the extraction of the symbols service, with its grammars and queries as URLs. */
export type OutlineRequest = { id: number; language: string; text: string };

/** `symbols` is null when the parse failed to load, and empty for a language without a grammar. */
export type OutlineAnswer = { id: number; symbols: CodeSymbol[] | null };

const NODE_MODULES = '../../symbols/node_modules/';

const QUERIES = '../../symbols/queries/';

// The `grammar` of each row of BROWSER_LANGUAGES, as literals, because the bundler emits every file that a glob matches.
const grammars = import.meta.glob<string>(
    [
        '../../symbols/node_modules/tree-sitter-javascript/tree-sitter-javascript.wasm',
        '../../symbols/node_modules/tree-sitter-typescript/tree-sitter-typescript.wasm',
        '../../symbols/node_modules/tree-sitter-typescript/tree-sitter-tsx.wasm',
        '../../symbols/node_modules/tree-sitter-python/tree-sitter-python.wasm',
        '../../symbols/node_modules/tree-sitter-go/tree-sitter-go.wasm',
        '../../symbols/node_modules/tree-sitter-rust/tree-sitter-rust.wasm',
        '../../symbols/node_modules/tree-sitter-java/tree-sitter-java.wasm',
        '../../symbols/node_modules/tree-sitter-c-sharp/tree-sitter-c_sharp.wasm',
        '../../symbols/node_modules/tree-sitter-ruby/tree-sitter-ruby.wasm',
        '../../symbols/node_modules/tree-sitter-bash/tree-sitter-bash.wasm',
        '../../symbols/node_modules/tree-sitter-scala/tree-sitter-scala.wasm',
        '../../symbols/node_modules/@tree-sitter-grammars/tree-sitter-zig/tree-sitter-zig.wasm',
        '../../symbols/node_modules/@tree-sitter-grammars/tree-sitter-lua/tree-sitter-lua.wasm',
        '../../symbols/node_modules/tree-sitter-php/tree-sitter-php.wasm',
    ],
    { query: '?url', import: 'default' },
);

const queries = import.meta.glob<string>('../../symbols/queries/*.scm', { query: '?raw', import: 'default' });

const files: LanguageFiles = {
    grammar: (path) => imported(grammars, `${NODE_MODULES}${path}`),
    query: (name) => imported(queries, `${QUERIES}${name}.scm`),
    runtime,
};

const languages = new Map<string, Promise<LoadedLanguage>>();

self.onmessage = async ({ data: { id, language, text } }: MessageEvent<OutlineRequest>) => {
    const row = BROWSER_LANGUAGES[language];
    let symbols: CodeSymbol[] | null = [];

    try {
        if (undefined !== row) {
            const loaded = languages.get(language) ?? loadLanguage(row, files);
            languages.set(language, loaded);
            symbols = extract(await loaded, text).symbols.map(({ kind, fqn, name, parentFqn, startLine, endLine, declarationStartLine, docStartLine }) => ({
                kind: kind as SymbolKind,
                fqn,
                name,
                parentFqn,
                range: { startLine, endLine },
                declarationStartLine,
                docStartLine,
            }));
        }
    } catch {
        languages.delete(language);
        symbols = null;
    }

    self.postMessage({ id, symbols } satisfies OutlineAnswer);
};

function imported(modules: Record<string, () => Promise<string>>, key: string): Promise<string> {
    const load = modules[key];

    return undefined === load ? Promise.reject(new Error(`The bundle has no ${key}.`)) : load();
}
