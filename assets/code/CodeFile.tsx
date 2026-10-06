import { cleanLastNewline, type CodeViewItem, type CodeViewLineSelection, type FileContents, type LineAnnotation, type SelectedLineRange } from '@pierre/diffs';
import { CodeView, type CodeViewHandle, type CodeViewReactOptions, WorkerPoolContextProvider } from '@pierre/diffs/react';
import { BaseStyles } from '@primer/react';
import { memo, type ReactNode, useCallback, useEffect, useMemo, useRef } from 'react';
import type { CodeAnnotation } from './annotations';
import { type LineRange, lineRange } from './lineHash';
import type { GestureRange } from './rangeSelection';
import { HIGHLIGHTER_OPTIONS, POOL_OPTIONS, THEME } from './workerPool';

export type Overflow = 'scroll' | 'wrap';

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
    /** The range of a click, a drag, Enter, or Space on the gutter "+". Null hides the "+". */
    onComment: ((range: GestureRange) => void) | null;
    overflow: Overflow;
    className?: string;
};

/**
 * The pool owns the theme while it works. This one applies when the view falls back to the main thread.
 */
const BASE_VIEW_OPTIONS = {
    theme: THEME,
    disableFileHeader: true,
    enableLineSelection: true,
} as const satisfies CodeViewReactOptions<undefined, undefined>;

/**
 * The only wrapper around `CodeView`. The worker pool outlives file changes, the view does not.
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
    overflow,
    className,
}: CodeFileProps) {
    const view = useRef<CodeViewHandle<undefined, undefined>>(null);
    const container = useRef<HTMLDivElement>(null);
    // The last range that the view reported. A different `selection` comes from outside and is scrolled to.
    const reported = useRef<LineRange | null>(null);
    // Changing view options renders the whole file again, so the options read the latest handler.
    const commentHandler = useRef(onComment);
    const latestSelection = useRef(selection);
    // The view applies a changed item only when its version changes.
    const itemVersion = useRef(0);
    const commentable = null !== onComment;

    useEffect(() => {
        commentHandler.current = onComment;
        latestSelection.current = selection;
    }, [onComment, selection]);

    useEffect(() => {
        const element = container.current;

        return null === element ? undefined : keyboardGutterUtility(element, () => latestSelection.current, (range) => commentHandler.current?.(range));
    }, []);

    // The language follows the name, so equal bytes at another path need their own highlighting.
    const file = useMemo<FileContents>(() => ({ name: path, contents: cleanLastNewline(contents), cacheKey: `${blobSha}:${path}` }), [path, blobSha, contents]);
    // An annotation change keeps the file object: while the first highlight is pending, the view compares file objects
    // by identity, and a new object of the same cacheKey leaves it blank for good.
    const items = useMemo<CodeViewItem<undefined>[]>(
        () => [{ id: path, type: 'file', file, annotations: [...annotations], version: ++itemVersion.current }],
        [path, file, annotations],
    );
    const selectedLines = useMemo<CodeViewLineSelection | null>(() => (null === selection ? null : { id: path, range: selection }), [path, selection]);
    const options = useMemo<CodeViewReactOptions<undefined, undefined>>(
        () => ({
            ...BASE_VIEW_OPTIONS,
            overflow,
            onLineSelected: (range: SelectedLineRange | null) => onSelectionCommit(toLineRange(range)),
            enableGutterUtility: commentable,
            onGutterUtilityClick: commentable ? ({ start, end }: SelectedLineRange) => commentHandler.current?.({ start, end }) : undefined,
        }),
        [onSelectionCommit, commentable, overflow],
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
            containerRef={container}
            items={items}
            selectedLines={selectedLines}
            onSelectedLinesChange={reportSelection}
            options={options}
            renderAnnotation={renderSlot}
            className={className}
        />
    );
});

/**
 * Decision D31. The library starts a comment on `pointerdown` only and gives the "+" no name. The "+" sits on the
 * last line of a selection and then acts on the whole selection.
 *
 * @returns The cleanup
 */
function keyboardGutterUtility(container: HTMLElement, selection: () => LineRange | null, comment: (range: GestureRange) => void): () => void {
    const utility = (event: Event): { button: HTMLButtonElement; range: LineRange } | null => {
        const [button] = event.composedPath();
        const line = button instanceof HTMLButtonElement && button.hasAttribute('data-utility-button') ? Number(button.closest('[data-column-number]')?.getAttribute('data-column-number')) : NaN;

        if (!(button instanceof HTMLButtonElement) || !Number.isInteger(line)) {
            return null;
        }
        const selected = selection();

        return { button, range: null !== selected && selected.end === line ? selected : lineRange(line, line) };
    };
    const name = (event: FocusEvent): void => {
        const found = utility(event);
        found?.button.setAttribute('aria-label', found.range.start === found.range.end ? `Comment on line ${found.range.start}` : `Comment on lines ${found.range.start} to ${found.range.end}`);
    };
    const press = (event: KeyboardEvent): void => {
        const found = 'Enter' === event.key || ' ' === event.key ? utility(event) : null;

        if (null !== found) {
            event.preventDefault();
            comment(found.range);
        }
    };
    container.addEventListener('focusin', name);
    container.addEventListener('keydown', press);

    return () => {
        container.removeEventListener('focusin', name);
        container.removeEventListener('keydown', press);
    };
}

function toLineRange(range: SelectedLineRange | null): LineRange | null {
    return null === range ? null : lineRange(range.start, range.end);
}

function isSameRange(first: LineRange, second: LineRange | null): boolean {
    return null !== second && first.start === second.start && first.end === second.end;
}
