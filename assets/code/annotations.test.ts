import { describe, expect, it } from 'vitest';
import { NO_ANNOTATIONS, stableAnnotations, threadsByLine } from './annotations';
import { bottomLine } from './rangeSelection';

type Thread = { id: string; endLine: number | null };

describe('AnnotationsTest', () => {
    it('Groups threads per line', () => {
        const threads: Thread[] = [
            { id: 'a', endLine: 3 },
            { id: 'file', endLine: null },
            { id: 'b', endLine: 7 },
            { id: 'c', endLine: 3 },
        ];

        const lines = threadsByLine(threads, (thread) => thread.endLine, 9);

        expect([...lines.keys()]).toEqual([3, 7, 9]);
        expect(lines.get(3)).toEqual({ threads: [threads[0], threads[3]], composer: false });
        expect(lines.get(7)).toEqual({ threads: [threads[2]], composer: false });
        expect(lines.get(9)).toEqual({ threads: [], composer: true });
        expect(threadsByLine(threads, (thread) => thread.endLine, 7).get(7)).toEqual({ threads: [threads[2]], composer: true });
    });

    it('Keeps the array identity when nothing changed', () => {
        const annotations = stableAnnotations(NO_ANNOTATIONS, [7, 3]);

        expect(stableAnnotations(annotations, new Set([3, 7]))).toBe(annotations);
        expect(stableAnnotations(annotations, [3])).toBe(annotations);
        expect(stableAnnotations(NO_ANNOTATIONS, [])).toBe(NO_ANNOTATIONS);
    });

    it('Appends new lines and keeps the index of every line', () => {
        const annotations = stableAnnotations(NO_ANNOTATIONS, [7, 3]);

        expect(annotations).toEqual([{ lineNumber: 7 }, { lineNumber: 3 }]);
        expect(stableAnnotations(annotations, [1, 3, 9])).toEqual([{ lineNumber: 7 }, { lineNumber: 3 }, { lineNumber: 1 }, { lineNumber: 9 }]);
    });

    it('Adds a line with a thread and a draft once', () => {
        const threadLines = [3, 7];
        const draftLines = [7, 9];

        expect(stableAnnotations(NO_ANNOTATIONS, [...threadLines, ...draftLines])).toEqual([{ lineNumber: 3 }, { lineNumber: 7 }, { lineNumber: 9 }]);
    });

    it('Finds the bottom line for both drag directions', () => {
        expect(bottomLine({ start: 5, end: 9 })).toBe(9);
        expect(bottomLine({ start: 9, end: 5 })).toBe(9);
        expect(bottomLine({ start: 10, end: 10 })).toBe(10);
    });
});
