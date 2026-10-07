import type { LineRange } from './anchor';
import type { SymbolKind } from './common';

export type Symbol = {
    kind: SymbolKind;
    fqn: string;
    /** Short name, for example `total`. */
    name: string;
    /** FQN of the nearest enclosing container: a class, interface, trait, enum, struct, or module. Null at the top level of the file. */
    parentFqn: string | null;
    /** The anchor range: from the doc comment, if any, to the last line of the node. */
    range: LineRange;
    /** First line of the node, attributes included, doc comment excluded. */
    declarationStartLine: number;
    docStartLine: number | null;
};
