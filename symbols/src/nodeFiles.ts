import { readFile } from 'node:fs/promises';
import type { LanguageFiles } from './languages.ts';

/** The grammars under `node_modules` and the query files of the service. */
export const NODE_FILES: LanguageFiles = {
    grammar: async (path) => new URL(`../node_modules/${path}`, import.meta.url),
    query: (name) => readFile(new URL(`../queries/${name}.scm`, import.meta.url), 'utf8'),
};
