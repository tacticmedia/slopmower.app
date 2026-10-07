import type { RepoPath, SymbolKind } from './common';

export type FileHit = {
    kind: 'file';
    path: RepoPath;
};

export type SymbolHit = {
    kind: 'symbol';
    /** The file that declares the symbol. */
    path: RepoPath;
    fqn: string;
    symbolKind: SymbolKind;
    /** First line of the node, attributes included, doc comment excluded. The file opens at this line. */
    declarationStartLine: number;
};

/** `kind` is an open set. */
export type SearchHit = FileHit | SymbolHit;
