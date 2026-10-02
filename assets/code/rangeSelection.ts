import type { AnchorInput } from '../api/types/anchor';
import type { LineRange } from './lineHash';

/** In gesture order: after a drag upwards, `end` is above `start`. */
export type GestureRange = { start: number; end: number };

/**
 * The comment box opens below this line.
 */
export function bottomLine({ start, end }: GestureRange): number {
    return Math.max(start, end);
}

/**
 * Q2 rules: `line` for one line, `range` for more, `file` without lines.
 */
export function anchorInput(path: string, range: LineRange | null): AnchorInput {
    if (null === range) {
        return { kind: 'file', path };
    }

    return { kind: range.start === range.end ? 'line' : 'range', path, range: { startLine: range.start, endLine: range.end } };
}
