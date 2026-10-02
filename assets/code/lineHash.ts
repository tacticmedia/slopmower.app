/** Inclusive, 1-based, `start <= end`. */
export type LineRange = { start: number; end: number };

const LINE_HASH = /^#L([1-9]\d*)(?:-L([1-9]\d*))?$/;

export function lineRange(first: number, second: number): LineRange {
    return { start: Math.min(first, second), end: Math.max(first, second) };
}

/**
 * Reads `#L10` and `#L10-L20`. Any other hash gives null.
 */
export function parseLineHash(hash: string): LineRange | null {
    const match = LINE_HASH.exec(hash);

    if (null === match) {
        return null;
    }
    const start = Number(match[1]);

    return lineRange(start, undefined === match[2] ? start : Number(match[2]));
}

export function formatLineHash({ start, end }: LineRange): string {
    return start === end ? `#L${start}` : `#L${start}-L${end}`;
}
