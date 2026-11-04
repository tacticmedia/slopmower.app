import type { ContentSnapshot } from '../api/types/anchor';

/** A truncated snapshot holds this many lines from the start of the region, then as many from its end. */
const TRUNCATED_PART_LINES = 100;

/** A `context` row is a `before` or an `after` line around the region. */
export type SnapshotRow = { kind: 'line' | 'context'; line: number; text: string } | { kind: 'gap'; hiddenLines: number };

/** Text fields that are null or absent give no rows. */
export function snapshotRows(startLine: number, snapshot: ContentSnapshot): SnapshotRow[] {
    const numbered = (kind: 'line' | 'context', texts: string[], firstLine: number): SnapshotRow[] => texts.map((text, index) => ({ kind, line: firstLine + index, text }));
    const lines = snapshot.lines ?? [];
    const beforeTexts = snapshot.before ?? [];
    const before = numbered('context', beforeTexts, startLine - beforeTexts.length);
    const after = numbered('context', snapshot.after ?? [], startLine + snapshot.lineCount);

    if (!snapshot.truncated) {
        return [...before, ...numbered('line', lines, startLine), ...after];
    }
    const head = lines.slice(0, TRUNCATED_PART_LINES);
    const tail = lines.slice(TRUNCATED_PART_LINES);

    return [
        ...before,
        ...numbered('line', head, startLine),
        { kind: 'gap', hiddenLines: snapshot.lineCount - lines.length },
        ...numbered('line', tail, startLine + snapshot.lineCount - tail.length),
        ...after,
    ];
}
