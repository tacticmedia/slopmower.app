import { CheckIcon, DownloadIcon } from '@primer/octicons-react';
import { Button, ButtonGroup, IconButton, SegmentedControl, Spinner } from '@primer/react';
import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useLoaderData, useLocation, useNavigate, useRouteLoaderData } from 'react-router';
import type { Actor } from '../../api/types/actor';
import type { Blame } from '../../api/types/blame';
import type { FileContent } from '../../api/types/file-content';
import type { Me } from '../../api/types/me';
import type { Symbol as CodeSymbol } from '../../api/types/symbol';
import { type CodeAnnotation, NO_ANNOTATIONS, stableAnnotations, threadsByLine } from '../../code/annotations';
import type { LineGeometry, NamePosition, Overflow } from '../../code/CodeFile';
import { type LineRange, lineRange } from '../../code/lineHash';
import { bottomLine, type GestureRange } from '../../code/rangeSelection';
import { enclosingScopes, matchingSymbol, symbolKindLabel } from '../../code/symbolMatch';
import { CommentBox, type CommentText } from '../../comments/CommentBox';
import { CommentOnLinesForm } from '../../comments/CommentOnLinesForm';
import { type DraftView, fileDraftViews, reviewLabel as reviewButtonLabel } from '../../comments/draftView';
import { ThreadList } from '../../comments/ThreadList';
import { type Composer, type SubmitMode, useFileComments } from '../../comments/useFileComments';
import { CopyButton } from '../../layout/CopyButton';
import { usePreference } from '../../layout/usePreference';
import { BlameColumn } from '../../review/BlameColumn';
import { BlameLegend } from '../../review/BlameLegend';
import { FileHeader } from '../../review/FileHeader';
import { blameHref, fileHref } from '../../review/fileHref';
import { FilePlaceholder } from '../../review/FilePlaceholder';
import { GoToDeclaration } from '../../review/GoToDeclaration';
import { formatSize } from '../../review/pathSegments';
import { ScopeLines } from '../../review/ScopeLines';
import { SymbolsMenu } from '../../review/SymbolsMenu';
import { ViewedCheckbox } from '../../review/ViewedCheckbox';
import { useGoToDeclaration } from './declarationsData';
import type { FileView } from './fileData';
import styles from './FileRoute.module.css';
import type { ReviewData } from './reviewData';
import { useFileSymbols } from './symbolsData';
import { useLineSelection } from './useLineSelection';

// A separate chunk, so the code viewer library stays out of the shell bundle.
const CodeFile = lazy(() => import('../../code/CodeFile').then(({ CodeFile }) => ({ default: CodeFile })));

type Comments = ReturnType<typeof useFileComments>;
type LineSelection = ReturnType<typeof useLineSelection>;

/**
 * Not keyed by path, so the worker pool in CodeFile stays up while files change.
 */
export function FileRoute() {
    const { content, text, threads, blame } = useLoaderData<FileView>();
    const { caller } = loaded(useRouteLoaderData<Me>('root'), 'root');
    const { review, drafts, viewedPaths } = loaded(useRouteLoaderData<ReviewData>('review'), 'review');
    const fileDrafts = useMemo(() => fileDraftViews(drafts, content.path), [drafts, content.path]);
    const selection = useLineSelection(content.path, content.lineCount);
    const comments = useFileComments({ path: content.path, threads, drafts: fileDrafts, caller, select: selection.select });
    const commentable = 'open' === review.state;
    // The blame view keeps one row height, so it has no threads and no line wrap.
    const blameMode = null !== blame;
    const writable = commentable && !blameMode;
    const reviewLabel = reviewButtonLabel(drafts);
    const { symbols, failed: symbolsFailed } = useFileSymbols(review.id, content.path, null !== text);
    const fileThreads = useMemo(() => comments.threads.filter((thread) => null === thread.range), [comments.threads]);
    const filePendingDrafts = useMemo(() => comments.drafts.filter((draft) => null === draft.range), [comments.drafts]);
    const fileComposer = null !== comments.composer && null === comments.composer.range ? comments.composer : null;
    const [wrap, setWrap] = usePreference('wrap');
    // V14 resolves names in PHP files only.
    const navigable = 'php' === content.language && null !== text;
    const goTo = useGoToDeclaration(review.id, content.path);
    const { select } = selection;
    const { open } = comments;
    // An exact symbol range anchors to the symbol.
    const openLines = useCallback((range: LineRange) => open(range, null === symbols ? null : matchingSymbol(symbols, range)), [open, symbols]);
    const commentOnLines = useCallback(
        (range: LineRange) => {
            select(range);
            openLines(range);
        },
        [select, openLines],
    );
    const commentOnSymbol = useCallback(
        (symbol: CodeSymbol) => {
            const range = lineRange(symbol.range.startLine, symbol.range.endLine);
            select(range);
            open(range, symbol);
        },
        [select, open],
    );

    const lines = useMemo(() => (null === text ? [] : text.split('\n')), [text]);
    // Kept per path, so the scopes of the previous file never show.
    const [scopes, setScopes] = useState<{ path: string; symbols: readonly CodeSymbol[] }>({ path: content.path, symbols: [] });
    // A ref, so FileRoute renders again only when the scopes change, not with each line that scrolls by.
    const topLine = useRef(1);
    const followTopLine = useCallback(
        (line: number) => {
            topLine.current = line;
            const next = null === symbols ? [] : enclosingScopes(symbols, line);
            setScopes((previous) => (previous.path === content.path && sameSymbols(previous.symbols, next) ? previous : { path: content.path, symbols: next }));
        },
        [symbols, content.path],
    );

    // The symbols can arrive after the scroll that set the top line.
    useEffect(() => followTopLine(topLine.current), [followTopLine]);
    const goToLine = useCallback((line: number) => select(lineRange(line, line)), [select]);

    return (
        <section className={styles.file} aria-label={content.path}>
            <FileHeader
                reviewId={review.id}
                repositoryName={review.repositoryName}
                path={content.path}
                planToApprove={threads.some((thread) => thread.proposalPending)}
                suggestion={threads.some((thread) => 'pending' === thread.suggestion)}
                summary={
                    <>
                        <ViewSwitch reviewId={review.id} path={content.path} blame={blameMode} canBlame={null !== text} />
                        <FileSummary content={content} text={text} />
                    </>
                }
                toolbarBelow={blameMode ? <BlameLegend /> : undefined}
                stuckBelow={<ScopeLines scopes={scopes.path === content.path ? scopes.symbols : []} lines={lines} onChoose={goToLine} />}
            >
                {navigable && <GoToDeclaration key={content.path} outcome={goTo.outcome} onSubmit={goTo.named} onChoose={(target) => void goTo.go(target)} onDismiss={goTo.dismiss} />}
                {null !== text && !blameMode && (
                    <Button size="small" aria-pressed={wrap} leadingVisual={wrap ? CheckIcon : undefined} onClick={() => setWrap(!wrap)}>
                        Wrap lines
                    </Button>
                )}
                {writable && null !== text && null !== content.lineCount && <CommentOnLinesForm lineCount={content.lineCount} onSubmit={commentOnLines} />}
                {writable && null !== text && <SymbolsMenu symbols={symbols} failed={symbolsFailed} onChoose={commentOnSymbol} />}
                {writable && (
                    <Button size="small" onClick={() => open(null)}>
                        Comment on file
                    </Button>
                )}
                <ViewedCheckbox path={content.path} viewed={viewedPaths.includes(content.path)} disabled={!commentable} />
                <RawActions content={content} text={text} />
            </FileHeader>
            {blame?.ignoreRevsFile && (
                <p className={styles.ignoreRevs} data-testid="ignore-revs">
                    Ignoring revisions in <code>.git-blame-ignore-revs</code>.
                </p>
            )}
            {!blameMode && (fileThreads.length > 0 || filePendingDrafts.length > 0 || null !== fileComposer) && (
                <div className={styles.fileThreads} data-testid="file-threads">
                    <ThreadList threads={fileThreads} caller={caller} drafts={filePendingDrafts} draftsEditable={commentable}>
                        {null !== fileComposer && <ComposerBox composer={fileComposer} reviewLabel={reviewLabel} onSubmit={comments.submit} onCancel={comments.cancel} />}
                    </ThreadList>
                </div>
            )}
            {null === text ? (
                <div className={styles.code}>
                    <FilePlaceholder content={content} />
                </div>
            ) : (
                <Code
                    content={content}
                    text={text}
                    selection={selection}
                    comments={comments}
                    caller={caller}
                    onComment={writable ? openLines : null}
                    onNavigate={navigable ? goTo.atPosition : null}
                    reviewLabel={reviewLabel}
                    overflow={wrap && !blameMode ? 'wrap' : 'scroll'}
                    onTopLineChange={followTopLine}
                    blame={blame}
                />
            )}
        </section>
    );
}

/** As on GitHub: `2343 lines (2021 loc) · 79.1 KB`. */
function FileSummary({ content, text }: { content: FileContent; text: string | null }) {
    const linesOfCode = useMemo(() => (null === text ? 0 : text.split('\n').filter((line) => '' !== line.trim()).length), [text]);
    const size = formatSize(content.sizeBytes);

    return <span className={styles.fileSummary}>{null === text || null === content.lineCount ? size : `${content.lineCount} lines (${linesOfCode} loc) · ${size}`}</span>;
}

/** GitHub's "Code | Blame": the two routes of the file, with the line hash kept. */
function ViewSwitch({ reviewId, path, blame, canBlame }: { reviewId: string; path: string; blame: boolean; canBlame: boolean }) {
    const navigate = useNavigate();
    const { hash } = useLocation();

    return (
        <SegmentedControl aria-label="File view" size="small" onChange={(index) => void navigate(`${0 === index ? fileHref(reviewId, path) : blameHref(reviewId, path)}${hash}`)}>
            <SegmentedControl.Button selected={!blame}>Code</SegmentedControl.Button>
            <SegmentedControl.Button selected={blame} disabled={!canBlame}>
                Blame
            </SegmentedControl.Button>
        </SegmentedControl>
    );
}

/** V8 serves the bytes as `text/plain` with a sandbox CSP, so "Raw" opens them in a tab. */
function RawActions({ content, text }: { content: FileContent; text: string | null }) {
    return (
        <ButtonGroup>
            <Button as="a" size="small" href={content.rawUrl} target="_blank" rel="noreferrer">
                Raw
            </Button>
            {null !== text && <CopyButton text={text} label="Copy raw file" iconOnly />}
            <IconButton as="a" size="small" href={content.rawUrl} download={content.path.slice(content.path.lastIndexOf('/') + 1)} icon={DownloadIcon} aria-label="Download raw file" />
        </ButtonGroup>
    );
}

type CodeProps = {
    content: FileContent;
    text: string;
    selection: LineSelection;
    comments: Comments;
    caller: Actor;
    /** Null on a closed review. */
    onComment: ((range: LineRange) => void) | null;
    /** Null for a file that is not PHP. */
    onNavigate: ((position: NamePosition) => void) | null;
    reviewLabel: string;
    overflow: Overflow;
    onTopLineChange(line: number): void;
    /** Set in the blame view, which shows no threads. */
    blame: Blame | null;
};

const draftLine = (draft: DraftView): number | null => (null === draft.range ? null : bottomLine(draft.range));

function Code({ content, text, selection, comments, caller, onComment, onNavigate, reviewLabel, overflow, onTopLineChange, blame }: CodeProps) {
    const { composer, threads, drafts, submit, cancel } = comments;
    const commentable = null !== onComment;
    const composerLine = null !== composer && null !== composer.range ? bottomLine(composer.range) : null;
    const lines = useMemo(() => threadsByLine(threads, (thread) => (null === thread.range ? null : bottomLine(thread.range)), composerLine), [threads, composerLine]);
    const draftLines = useMemo(() => threadsByLine(drafts, draftLine, null), [drafts]);
    // Kept per path: a line keeps its annotation index while its file is shown.
    const [annotated, setAnnotated] = useState<{ path: string; annotations: readonly CodeAnnotation[] }>({ path: content.path, annotations: NO_ANNOTATIONS });
    const currentAnnotations = stableAnnotations(annotated.path === content.path ? annotated.annotations : NO_ANNOTATIONS, [...lines.keys(), ...draftLines.keys()]);

    if (annotated.path !== content.path || currentAnnotations !== annotated.annotations) {
        setAnnotated({ path: content.path, annotations: currentAnnotations });
    }

    const renderAnnotation = useCallback(
        (lineNumber: number) => {
            const line = lines.get(lineNumber);
            const lineDrafts = draftLines.get(lineNumber)?.threads;

            return undefined === line && undefined === lineDrafts ? null : (
                <ThreadList threads={line?.threads ?? []} caller={caller} drafts={lineDrafts} draftsEditable={commentable}>
                    {true === line?.composer && null !== composer && <ComposerBox composer={composer} reviewLabel={reviewLabel} onSubmit={submit} onCancel={cancel} />}
                </ThreadList>
            );
        },
        [lines, draftLines, caller, commentable, composer, reviewLabel, submit, cancel],
    );
    const comment = useCallback((gesture: GestureRange) => onComment?.(lineRange(gesture.start, gesture.end)), [onComment]);
    const beside = useCallback((geometry: LineGeometry | null) => (null === blame ? null : <BlameColumn blame={blame} geometry={geometry} />), [blame]);

    return (
        <div className={styles.code} data-testid="code-view">
            <Suspense
                fallback={
                    <div className={styles.loading}>
                        <Spinner srText="Loading the code" />
                    </div>
                }
            >
                <CodeFile
                    path={content.path}
                    blobSha={content.blobSha}
                    contents={text}
                    selection={selection.selection}
                    onSelectionChange={selection.change}
                    onSelectionCommit={selection.commit}
                    annotations={null === blame ? currentAnnotations : NO_ANNOTATIONS}
                    renderAnnotation={renderAnnotation}
                    onComment={null === onComment ? null : comment}
                    onNavigate={onNavigate}
                    overflow={overflow}
                    onTopLineChange={onTopLineChange}
                    beside={null === blame ? undefined : beside}
                />
            </Suspense>
        </div>
    );
}

type ComposerBoxProps = {
    composer: Composer;
    /** "Start a review" or "Add review comment". */
    reviewLabel: string;
    onSubmit(text: CommentText, mode: SubmitMode): boolean;
    onCancel(): void;
};

function ComposerBox({ composer, reviewLabel, onSubmit, onCancel }: ComposerBoxProps) {
    return (
        <CommentBox
            key={composer.key}
            label={composerLabel(composer)}
            labelVisible
            submitLabel="Add single comment"
            onSubmit={(text) => onSubmit(text, 'single')}
            alternative={{ label: reviewLabel, onSubmit: (text) => onSubmit(text, 'draft') }}
            askForPlan={composer.requireApproval}
            titled
            onCancel={onCancel}
            initialTitle={composer.initialTitle}
            initialBody={composer.initialBody}
            error={composer.error}
            busy={false}
            focusOnMount
        />
    );
}

function composerLabel({ range, symbol }: Composer): string {
    if (null !== symbol) {
        return `Comment on ${symbolKindLabel(symbol)} ${symbol.fqn}`;
    }

    if (null === range) {
        return 'Comment on file';
    }

    return range.start === range.end ? `Comment on line ${range.start}` : `Comment on lines ${range.start} to ${range.end}`;
}

function sameSymbols(first: readonly CodeSymbol[], second: readonly CodeSymbol[]): boolean {
    return first.length === second.length && first.every((symbol, index) => symbol === second[index]);
}

function loaded<T>(data: T | undefined, routeId: string): T {
    if (undefined === data) {
        throw new Error(`The file route renders below the ${routeId} route.`);
    }

    return data;
}
