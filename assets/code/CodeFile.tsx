import { cleanLastNewline, type FileContents, type LineAnnotation, type SelectedLineRange, VirtualizedFile, Virtualizer } from '@pierre/diffs';
import { File, type FileOptions, VirtualizerContext, WorkerPoolContextProvider } from '@pierre/diffs/react';
import { BaseStyles } from '@primer/react';
import { memo, type ReactNode, type RefObject, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { CodeAnnotation } from './annotations';
import { characterColumn } from './characterColumn';
import { type LineRange, lineRange } from './lineHash';
import type { GestureRange } from './rangeSelection';
import { centredScrollTop, isBelowHeader, stickyHeaderPx } from './scrollTarget';
import { HIGHLIGHTER_OPTIONS, POOL_OPTIONS, THEME } from './workerPool';

export type Overflow = 'scroll' | 'wrap';

/** A V14 position: `column` is 1-based, in Unicode characters of the line. */
export type NamePosition = { line: number; column: number };

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
    /** Cmd-click, or Ctrl-click, on a token. Null turns code navigation off. */
    onNavigate: ((position: NamePosition) => void) | null;
    overflow: Overflow;
    /** The first line below the sticky header, after each scroll that changes it. */
    onTopLineChange?: (line: number) => void;
};

type FileViewOptions = FileOptions<undefined, undefined>;

/**
 * The pool owns the theme while it works. This one applies when the view falls back to the main thread.
 */
const BASE_VIEW_OPTIONS = {
    theme: THEME,
    disableFileHeader: true,
    enableLineSelection: true,
} as const satisfies FileViewOptions;

/** The scroll to a line corrects itself with the rendered rows, at most this often. */
const MAXIMUM_SCROLL_MOVES = 60;

/** How long the scroll to a line waits for the view to know the lines of the file. */
const MAXIMUM_SCROLL_WAIT_MS = 10000;

/**
 * The only wrapper around the `@pierre/diffs` file view. The code scrolls with the window (D55): the view renders
 * the rows near the viewport of the window. The worker pool and the virtualizer outlive file changes, the view does not.
 */
export const CodeFile = memo(function CodeFile(props: CodeFileProps) {
    const [virtualizer] = useState(() => new Virtualizer());

    useEffect(() => {
        virtualizer.setup(document);

        return () => virtualizer.cleanUp();
    }, [virtualizer]);

    return (
        <WorkerPoolContextProvider poolOptions={POOL_OPTIONS} highlighterOptions={HIGHLIGHTER_OPTIONS}>
            <VirtualizerContext.Provider value={virtualizer}>
                <FileView key={props.path} {...props} />
            </VirtualizerContext.Provider>
        </WorkerPoolContextProvider>
    );
});

type ScrollRequest = { range: LineRange; moves: number; startedAtMs: number; running: boolean };

type ScrollStep = 'done' | 'moved' | 'waiting';

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
    onNavigate,
    overflow,
    onTopLineChange,
}: CodeFileProps) {
    const container = useRef<HTMLDivElement>(null);
    const rendered = useRef<{ node: HTMLElement; file: VirtualizedFile<undefined, undefined> } | null>(null);
    const pendingScroll = useRef<ScrollRequest | null>(null);
    // The last range that the view reported. A different `selection` comes from outside and is scrolled to.
    const reported = useRef<LineRange | null>(null);
    // Changing view options renders the whole file again, so the options read the latest handler.
    const commentHandler = useRef(onComment);
    const navigateHandler = useRef(onNavigate);
    const topLineHandler = useRef(onTopLineChange);
    const measureTopLine = useRef<() => void>(() => undefined);
    const latestSelection = useRef(selection);
    const hoveredToken = useRef<HTMLElement | null>(null);
    const commentable = null !== onComment;
    const navigable = null !== onNavigate;

    useEffect(() => {
        commentHandler.current = onComment;
        navigateHandler.current = onNavigate;
        topLineHandler.current = onTopLineChange;
        latestSelection.current = selection;
    }, [onComment, onNavigate, onTopLineChange, selection]);

    useEffect(() => {
        let frame = 0;
        let reportedLine = 0;
        const measure = (): void => {
            frame = 0;
            const current = rendered.current;
            if (null === current) {
                return;
            }
            const belowHeader = stickyHeaderPx() - current.node.getBoundingClientRect().top;
            const line = belowHeader <= 0 ? 1 : current.file.getNumericScrollAnchor(belowHeader)?.lineNumber;
            if (undefined !== line && line !== reportedLine) {
                reportedLine = line;
                topLineHandler.current?.(line);
            }
        };
        const schedule = (): void => {
            frame ||= requestAnimationFrame(measure);
        };
        measureTopLine.current = schedule;
        window.addEventListener('scroll', schedule, { passive: true });

        return () => {
            measureTopLine.current = () => undefined;
            window.removeEventListener('scroll', schedule);
            cancelAnimationFrame(frame);
        };
    }, []);

    useEffect(() => {
        const element = container.current;

        return null === element ? undefined : keyboardGutterUtility(element, () => latestSelection.current, (range) => commentHandler.current?.(range));
    }, []);

    useEffect(() => (navigable ? underlineWhileModifierHeld(hoveredToken) : undefined), [navigable]);

    // The language follows the name, so equal bytes at another path need their own highlighting.
    const file = useMemo<FileContents>(() => ({ name: path, contents: cleanLastNewline(contents), cacheKey: `${blobSha}:${path}` }), [path, blobSha, contents]);
    const latestContents = useRef(file.contents);

    useEffect(() => {
        latestContents.current = file.contents;
    }, [file]);

    const lineAnnotations = useMemo<LineAnnotation<undefined>[]>(() => [...annotations], [annotations]);
    const selectedLines = useMemo<SelectedLineRange | null>(() => (null === selection ? null : { start: selection.start, end: selection.end }), [selection]);
    const reportSelection = useCallback(
        (range: SelectedLineRange | null) => {
            reported.current = toLineRange(range);
            onSelectionChange(reported.current);
        },
        [onSelectionChange],
    );
    // Starts once the view has an instance, from `onPostRender` or from a new selection, then runs once per frame.
    const scrollWhenRendered = useCallback(() => {
        const request = pendingScroll.current;
        if (null === request || null === rendered.current || request.running) {
            return;
        }
        request.running = true;
        const step = (): void => {
            if (pendingScroll.current !== request || null === rendered.current) {
                return;
            }
            const result = scrollToRange(rendered.current.node, rendered.current.file, request.range);
            if ('moved' === result) {
                request.moves++;
            }
            if ('done' === result || request.moves >= MAXIMUM_SCROLL_MOVES || performance.now() - request.startedAtMs > MAXIMUM_SCROLL_WAIT_MS) {
                pendingScroll.current = null;

                return;
            }
            requestAnimationFrame(step);
        };
        step();
    }, []);
    const options = useMemo<FileViewOptions>(
        () => ({
            ...BASE_VIEW_OPTIONS,
            overflow,
            onLineSelectionStart: reportSelection,
            onLineSelectionChange: reportSelection,
            // Not `onLineSelected`: the view also fires it when it applies the controlled selection, and a commit of
            // that would replace the hash during a navigation. The end of a gesture comes with the same range.
            onLineSelectionEnd: (range: SelectedLineRange | null) => {
                reportSelection(range);
                onSelectionCommit(toLineRange(range));
            },
            enableGutterUtility: commentable,
            onGutterUtilityClick: commentable ? ({ start, end }: SelectedLineRange) => commentHandler.current?.({ start, end }) : undefined,
            onPostRender: (node, instance, phase) => {
                rendered.current = 'unmount' !== phase && instance instanceof VirtualizedFile ? { node, file: instance } : null;
                scrollWhenRendered();
                measureTopLine.current();
            },
            ...(navigable
                ? {
                      onTokenClick: (token, event) => {
                          if (isModifierHeld(event)) {
                              navigateHandler.current?.({ line: token.lineNumber, column: characterColumn(latestContents.current, token.lineNumber, token.lineCharStart) });
                          }
                      },
                      onTokenEnter: (token, event) => {
                          hoveredToken.current = token.tokenElement;
                          underline(token.tokenElement, isModifierHeld(event));
                      },
                      onTokenLeave: (token) => {
                          hoveredToken.current = null;
                          underline(token.tokenElement, false);
                      },
                  }
                : {}),
        }),
        [reportSelection, onSelectionCommit, scrollWhenRendered, commentable, navigable, overflow],
    );
    // Without the wrapper, annotation content inherits the code font of the view.
    const renderSlot = useCallback((annotation: LineAnnotation<undefined>) => <BaseStyles>{renderAnnotation(annotation.lineNumber)}</BaseStyles>, [renderAnnotation]);

    useEffect(() => {
        if (null !== selection && !isSameRange(selection, reported.current)) {
            pendingScroll.current = { range: selection, moves: 0, startedAtMs: performance.now(), running: false };
            scrollWhenRendered();
        }
    }, [selection, scrollWhenRendered]);

    return (
        <div ref={container}>
            <File file={file} lineAnnotations={lineAnnotations} selectedLines={selectedLines} options={options} renderAnnotation={renderSlot} />
        </div>
    );
});

/**
 * Centres the range below the sticky header. Before the rows exist, the position comes from the line heights that
 * the view knows; once they are rendered, from the rows themselves.
 *
 * @returns `waiting` while the view does not know the lines yet
 */
function scrollToRange(node: HTMLElement, file: VirtualizedFile<undefined, undefined>, range: LineRange): ScrollStep {
    const headerPx = stickyHeaderPx();
    const first = node.shadowRoot?.querySelector(`[data-line="${range.start}"]`);
    const last = node.shadowRoot?.querySelector(`[data-line="${range.end}"]`);

    if (first instanceof HTMLElement && last instanceof HTMLElement) {
        const span = { top: first.getBoundingClientRect().top, bottom: last.getBoundingClientRect().bottom };
        if (isBelowHeader(span, headerPx, window.innerHeight)) {
            return 'done';
        }
        window.scrollTo({ top: centredScrollTop({ top: span.top + window.scrollY, bottom: span.bottom + window.scrollY }, headerPx, window.innerHeight), behavior: 'instant' });

        return 'moved';
    }
    const start = file.getLinePosition(range.start);
    const end = file.getLinePosition(range.end);
    // A height of 0: the view does not know the lines yet.
    if (undefined === start || undefined === end || 0 === end.height) {
        return 'waiting';
    }
    const fileTop = node.getBoundingClientRect().top + window.scrollY;
    window.scrollTo({ top: centredScrollTop({ top: fileTop + start.top, bottom: fileTop + end.top + end.height }, headerPx, window.innerHeight), behavior: 'instant' });

    return 'moved';
}

/**
 * Decision D31. The library starts a comment on `pointerdown` only and gives the "+" no name. The "+" sits on the
 * last line of a selection and then acts on the whole selection.
 *
 * @returns The cleanup
 */
function keyboardGutterUtility(container: HTMLElement, selection: () => LineRange | null, comment: (range: GestureRange) => void): () => void {
    const utility = (event: Event): { button: HTMLButtonElement; line: number; range: LineRange } | null => {
        const [button] = event.composedPath();
        const line = button instanceof HTMLButtonElement && button.hasAttribute('data-utility-button') ? Number(button.closest('[data-column-number]')?.getAttribute('data-column-number')) : NaN;

        if (!(button instanceof HTMLButtonElement) || !Number.isInteger(line)) {
            return null;
        }
        const selected = selection();

        return { button, line, range: null !== selected && selected.end === line ? selected : lineRange(line, line) };
    };
    const press = (event: KeyboardEvent): void => {
        const found = 'Enter' === event.key || ' ' === event.key ? utility(event) : null;

        if (null !== found) {
            event.preventDefault();
            comment(found.range);
        }
    };
    // A render after a scroll inserts the focused "+" into its row again, which drops the focus.
    let focused: { root: ShadowRoot; line: number } | null = null;
    const regainFocus = (records: MutationRecord[]): void => {
        const plus = focused?.root.querySelector(`[data-column-number="${focused.line}"] [data-utility-button]`);

        if (plus instanceof HTMLElement && document.body === document.activeElement && records.some((record) => [...record.addedNodes].some((node) => node.contains(plus)))) {
            plus.focus({ preventScroll: true });
        }
    };
    const insertions = new MutationObserver(regainFocus);
    const release = (): void => {
        focused = null;
        insertions.disconnect();
    };
    const focusIn = (event: FocusEvent): void => {
        const found = utility(event);
        const root = found?.button.getRootNode();

        if (null === found || !(root instanceof ShadowRoot)) {
            return;
        }
        found.button.setAttribute('aria-label', found.range.start === found.range.end ? `Comment on line ${found.range.start}` : `Comment on lines ${found.range.start} to ${found.range.end}`);
        focused = { root, line: found.line };
        insertions.observe(root, { childList: true, subtree: true });
    };
    const focusOut = (event: FocusEvent): void => {
        if (null === utility(event)) {
            return;
        }
        if (null !== event.relatedTarget) {
            release();

            return;
        }
        // A render that drops the focus inserts the "+" again in the same task. Its records reach the observer before
        // this microtask, or through takeRecords().
        queueMicrotask(() => {
            regainFocus(insertions.takeRecords());

            if (document.body === document.activeElement) {
                release();
            }
        });
    };
    container.addEventListener('focusin', focusIn);
    container.addEventListener('focusout', focusOut);
    container.addEventListener('keydown', press);

    return () => {
        container.removeEventListener('focusin', focusIn);
        container.removeEventListener('focusout', focusOut);
        container.removeEventListener('keydown', press);
        release();
    };
}

/**
 * Cmd on macOS. Ctrl elsewhere: a Ctrl-click on macOS opens the context menu and sends no click.
 */
function isModifierHeld(event: MouseEvent | KeyboardEvent): boolean {
    return event.metaKey || event.ctrlKey;
}

function underline(token: HTMLElement, on: boolean): void {
    token.style.textDecoration = on ? 'underline' : '';
    token.style.cursor = on ? 'pointer' : '';
}

/**
 * The view reports a token on pointer moves only, so a key press under a resting pointer needs its own listener.
 *
 * @returns The cleanup
 */
function underlineWhileModifierHeld(hovered: RefObject<HTMLElement | null>): () => void {
    const toggle = (event: KeyboardEvent): void => {
        if (null !== hovered.current) {
            underline(hovered.current, isModifierHeld(event));
        }
    };
    window.addEventListener('keydown', toggle);
    window.addEventListener('keyup', toggle);

    return () => {
        window.removeEventListener('keydown', toggle);
        window.removeEventListener('keyup', toggle);
    };
}

function toLineRange(range: SelectedLineRange | null): LineRange | null {
    return null === range ? null : lineRange(range.start, range.end);
}

function isSameRange(first: LineRange, second: LineRange | null): boolean {
    return null !== second && first.start === second.start && first.end === second.end;
}
