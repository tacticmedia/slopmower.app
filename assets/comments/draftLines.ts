import { createContext } from 'react';
import type { AnchorInput } from '../api/types/anchor';
import type { LineRange } from '../code/lineHash';

/** The file view of a pending comment: the edit can move it to other lines (D115). */
export type DraftLines = {
    lineCount: number;
    /** As for a new comment: an exact symbol range anchors to the symbol. */
    anchor(range: LineRange): AnchorInput;
    /** Shows `range` in the code while the draft `key` is edited. Null ends it. */
    highlight(key: string, range: LineRange | null): void;
};

export const DraftLinesContext = createContext<DraftLines | null>(null);
