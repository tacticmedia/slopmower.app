/** A line that carries an annotation. Structurally a `LineAnnotation` of the code view. */
export type CodeAnnotation = { readonly lineNumber: number };

export type LineThreads<T> = { threads: T[]; composer: boolean };

export const NO_ANNOTATIONS: readonly CodeAnnotation[] = [];

/**
 * One entry per line, because the code view names annotation slots by line. Threads without an end line are left out.
 */
export function threadsByLine<T>(threads: readonly T[], endLine: (thread: T) => number | null, composerLine: number | null): Map<number, LineThreads<T>> {
    const lines = new Map<number, LineThreads<T>>();
    const at = (line: number): LineThreads<T> => {
        const existing = lines.get(line);
        if (undefined !== existing) {
            return existing;
        }
        const created: LineThreads<T> = { threads: [], composer: false };
        lines.set(line, created);

        return created;
    };

    for (const thread of threads) {
        const line = endLine(thread);
        if (null !== line) {
            at(line).threads.push(thread);
        }
    }
    if (null !== composerLine) {
        at(composerLine).composer = true;
    }

    return lines;
}

/**
 * Appends the lines that `previous` lacks, each once, so every line keeps its index: the code view keys slot content
 * by index, and a shifted index remounts it. A line without threads stays and renders nothing. A new array makes the
 * code view render the whole file again, so `previous` comes back while no line is new.
 */
export function stableAnnotations(previous: readonly CodeAnnotation[], lines: Iterable<number>): readonly CodeAnnotation[] {
    const known = new Set(previous.map(({ lineNumber }) => lineNumber));
    const added = [...new Set(lines)].filter((line) => !known.has(line));

    return 0 === added.length ? previous : [...previous, ...added.map((lineNumber) => ({ lineNumber }))];
}
