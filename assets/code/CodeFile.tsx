import { cleanLastNewline, type CodeViewItem, type CodeViewLineSelection, type SelectedLineRange } from '@pierre/diffs';
import { CodeView, type CodeViewHandle, type CodeViewReactOptions, WorkerPoolContextProvider } from '@pierre/diffs/react';
import type { WorkerInitializationRenderOptions, WorkerPoolOptions } from '@pierre/diffs/worker';
import diffsWorkerUrl from '@pierre/diffs/worker/worker.js?worker&url';
import { memo, useCallback, useEffect, useMemo, useRef } from 'react';
import { type LineRange, lineRange } from './lineHash';

export type CodeFileProps = {
    path: string;
    /** With `path`, the highlight cache key of the worker pool. */
    blobSha: string;
    contents: string;
    selection: LineRange | null;
    /** Every change, also during a drag. Feed it back into `selection`. */
    onSelectionChange(selection: LineRange | null): void;
    /** The selection after the pointer is released. */
    onSelectionCommit(selection: LineRange | null): void;
    className?: string;
};

const THEME = { light: 'github-light', dark: 'github-dark' } as const;

const POOL_OPTIONS: WorkerPoolOptions = {
    workerFactory: () => new Worker(diffsWorkerUrl, { type: 'module' }),
    poolSize: 2,
};

// No `langs`: each grammar loads with the first file that needs it.
const HIGHLIGHTER_OPTIONS: WorkerInitializationRenderOptions = { theme: THEME };

/**
 * The pool owns the theme while it works. This one applies when the view falls back to the main thread.
 */
const BASE_VIEW_OPTIONS = {
    theme: THEME,
    disableFileHeader: true,
    enableLineSelection: true,
} as const satisfies CodeViewReactOptions<undefined, undefined>;

/**
 * The only wrapper around `@pierre/diffs`. The worker pool outlives file changes, the view does not.
 */
export const CodeFile = memo(function CodeFile(props: CodeFileProps) {
    return (
        <WorkerPoolContextProvider poolOptions={POOL_OPTIONS} highlighterOptions={HIGHLIGHTER_OPTIONS}>
            <FileView key={props.path} {...props} />
        </WorkerPoolContextProvider>
    );
});

const FileView = memo(function FileView({ path, blobSha, contents, selection, onSelectionChange, onSelectionCommit, className }: CodeFileProps) {
    const view = useRef<CodeViewHandle<undefined, undefined>>(null);
    // The last range that the view reported. A different `selection` comes from outside and is scrolled to.
    const reported = useRef<LineRange | null>(null);

    const items = useMemo<CodeViewItem<undefined>[]>(
        // The language follows the name, so equal bytes at another path need their own highlighting.
        () => [{ id: path, type: 'file', file: { name: path, contents: cleanLastNewline(contents), cacheKey: `${blobSha}:${path}` } }],
        [path, blobSha, contents],
    );
    const selectedLines = useMemo<CodeViewLineSelection | null>(() => (null === selection ? null : { id: path, range: selection }), [path, selection]);
    const options = useMemo<CodeViewReactOptions<undefined, undefined>>(
        () => ({ ...BASE_VIEW_OPTIONS, onLineSelected: (range: SelectedLineRange | null) => onSelectionCommit(toLineRange(range)) }),
        [onSelectionCommit],
    );
    const reportSelection = useCallback(
        (next: CodeViewLineSelection | null) => {
            reported.current = toLineRange(next?.range ?? null);
            onSelectionChange(reported.current);
        },
        [onSelectionChange],
    );

    useEffect(() => {
        if (null !== selection && !isSameRange(selection, reported.current)) {
            view.current?.scrollTo({ type: 'range', id: path, range: selection, align: 'center' });
        }
    }, [path, selection]);

    return <CodeView ref={view} items={items} selectedLines={selectedLines} onSelectedLinesChange={reportSelection} options={options} className={className} />;
});

function toLineRange(range: SelectedLineRange | null): LineRange | null {
    return null === range ? null : lineRange(range.start, range.end);
}

function isSameRange(first: LineRange, second: LineRange | null): boolean {
    return null !== second && first.start === second.start && first.end === second.end;
}
