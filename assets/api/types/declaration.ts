import type { LineRange } from './anchor';
import type { RepoPath, SymbolKind } from './common';

export type Declaration = {
    path: RepoPath;
    fqn: string;
    kind: SymbolKind;
    /** The symbol range of V9: from the doc comment, if any, to the last line of the node. */
    range: LineRange;
    /** First line of the node, attributes included, doc comment excluded. The jump target. */
    declarationStartLine: number;
};
