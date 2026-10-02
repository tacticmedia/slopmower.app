import { cleanLastNewline, type CodeViewItem, type CodeViewLineSelection, type LineAnnotation, type SelectedLineRange } from '@pierre/diffs';
import { CodeView, type CodeViewHandle, type CodeViewReactOptions, WorkerPoolContextProvider } from '@pierre/diffs/react';
import type { WorkerInitializationRenderOptions, WorkerPoolOptions } from '@pierre/diffs/worker';
import diffsWorkerUrl from '@pierre/diffs/worker/worker.js?worker&url';
import { BaseStyles } from '@primer/react';
import { memo, type ReactNode, useCallback, useEffect, useMemo, useRef } from 'react';
import type { CodeAnnotation } from './annotations';
import { type LineRange, lineRange } from './lineHash';
import type { GestureRange } from './rangeSelection';

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
    /** One per line. Pass the same array while the lines stay the same, or the view renders the whole file again. */
    annotations: readonly CodeAnnotation[];
    renderAnnotation(lineNumber: number): ReactNode;
    /** The range of a click or drag on the gutter "+". Null hides the "+". */
    onComment: ((range: GestureRange) => void) | null;
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

const FileView = memo(function FileView({
    path,
    blobSha,
    contents,
    selection,
    onSelectionChange,
    onSelectionCommit,
    annotations,
    renderAnnotation,
    onComment,
    className,
}: CodeFileProps) {
    const view = useRef<CodeViewHandle<undefined, undefined>>(null);
    // The last range that the view reported. A different `selection` comes from outside and is scrolled to.
    const reported = useRef<LineRange | null>(null);
    // Changing view options renders the whole file again, so the options read the latest handler.
    const commentHandler = useRef(onComment);
    // The view applies a changed item only when its version changes.
    const itemVersion = useRef(0);
    const commentable = null !== onComment;

    useEffect(() => {
        commentHandler.current = onComment;
    }, [onComment]);

    const items = useMemo<CodeViewItem<undefined>[]>(
        () => [
            {
                id: path,
                type: 'file',
                // The language follows the name, so equal bytes at another path need their own highlighting.
                file: { name: path, contents: cleanLastNewline(contents), cacheKey: `${blobSha}:${path}` },
                annotations: [...annotations],
                version: ++itemVersion.current,
            },
        ],
        [path, blobSha, contents, annotations],
    );
    const selectedLines = useMemo<CodeViewLineSelection | null>(() => (null === selection ? null : { id: path, range: selection }), [path, selection]);
    const options = useMemo<CodeViewReactOptions<undefined, undefined>>(
        () => ({
            ...BASE_VIEW_OPTIONS,
            onLineSelected: (range: SelectedLineRange | null) => onSelectionCommit(toLineRange(range)),
            enableGutterUtility: commentable,
            onGutterUtilityClick: commentable ? ({ start, end }: SelectedLineRange) => commentHandler.current?.({ start, end }) : undefined,
        }),
        [onSelectionCommit, commentable],
    );
    const reportSelection = useCallback(
        (next: CodeViewLineSelection | null) => {
            reported.current = toLineRange(next?.range ?? null);
            onSelectionChange(reported.current);
        },
        [onSelectionChange],
    );
    // Without the wrapper, annotation content inherits the code font of the view.
    const renderSlot = useCallback((annotation: LineAnnotation<undefined>) => <BaseStyles>{renderAnnotation(annotation.lineNumber)}</BaseStyles>, [renderAnnotation]);

    useEffect(() => {
        if (null !== selection && !isSameRange(selection, reported.current)) {
            view.current?.scrollTo({ type: 'range', id: path, range: selection, align: 'center' });
        }
    }, [path, selection]);

    return (
        <CodeView
            ref={view}
            items={items}
            selectedLines={selectedLines}
            onSelectedLinesChange={reportSelection}
            options={options}
            renderAnnotation={renderSlot}
            className={className}
        />
    );
});

function toLineRange(range: SelectedLineRange | null): LineRange | null {
    return null === range ? null : lineRange(range.start, range.end);
}

function isSameRange(first: LineRange, second: LineRange | null): boolean {
    return null !== second && first.start === second.start && first.end === second.end;
}
