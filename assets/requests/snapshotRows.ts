import type { ContentSnapshot } from '../api/types/anchor';

/** A truncated snapshot holds this many lines from the start of the region, then as many from its end. */
const TRUNCATED_PART_LINES = 100;

export type SnapshotRow = { kind: 'line'; line: number; text: string } | { kind: 'gap'; hiddenLines: number };

export function snapshotRows(startLine: number, snapshot: ContentSnapshot): SnapshotRow[] {
    const numbered = (texts: string[], firstLine: number): SnapshotRow[] => texts.map((text, index) => ({ kind: 'line', line: firstLine + index, text }));

    if (!snapshot.truncated) {
        return numbered(snapshot.lines, startLine);
    }
    const head = snapshot.lines.slice(0, TRUNCATED_PART_LINES);
    const tail = snapshot.lines.slice(TRUNCATED_PART_LINES);

    return [
        ...numbered(head, startLine),
        { kind: 'gap', hiddenLines: snapshot.lineCount - snapshot.lines.length },
        ...numbered(tail, startLine + snapshot.lineCount - tail.length),
    ];
}
