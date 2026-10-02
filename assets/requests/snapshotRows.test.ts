import { describe, expect, it } from 'vitest';
import { snapshotRows } from './snapshotRows';

describe('SnapshotRowsTest', () => {
    it('Numbers the tail of a truncated snapshot from the end of the region', () => {
        const lines = Array.from({ length: 200 }, (_, index) => `line ${index}`);

        const rows = snapshotRows(10, { lines, truncated: true, lineCount: 250, before: [], after: [], normalizedHash: 'sha256:0' });

        expect(rows).toHaveLength(201);
        expect(rows[0]).toEqual({ kind: 'line', line: 10, text: 'line 0' });
        expect(rows[99]).toEqual({ kind: 'line', line: 109, text: 'line 99' });
        expect(rows[100]).toEqual({ kind: 'gap', hiddenLines: 50 });
        expect(rows[101]).toEqual({ kind: 'line', line: 160, text: 'line 100' });
        expect(rows[200]).toEqual({ kind: 'line', line: 259, text: 'line 199' });
    });
});
