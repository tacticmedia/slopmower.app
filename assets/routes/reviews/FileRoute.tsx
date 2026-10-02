import { Button, Spinner } from '@primer/react';
import { lazy, Suspense, useCallback, useMemo, useState } from 'react';
import { useLoaderData, useRouteLoaderData } from 'react-router';
import type { Actor } from '../../api/types/actor';
import type { FileContent } from '../../api/types/file-content';
import type { Me } from '../../api/types/me';
import type { Review } from '../../api/types/review';
import { type CodeAnnotation, NO_ANNOTATIONS, stableAnnotations, threadsByLine } from '../../code/annotations';
import { type LineRange, lineRange } from '../../code/lineHash';
import { bottomLine, type GestureRange } from '../../code/rangeSelection';
import { CommentBox } from '../../comments/CommentBox';
import { CommentOnLinesForm } from '../../comments/CommentOnLinesForm';
import { ThreadList } from '../../comments/ThreadList';
import { type Composer, useFileComments } from '../../comments/useFileComments';
import { FileHeader } from '../../review/FileHeader';
import { FilePlaceholder } from '../../review/FilePlaceholder';
import type { FileView } from './fileData';
import styles from './FileRoute.module.css';
import { useLineSelection } from './useLineSelection';

// A separate chunk, so the code viewer library stays out of the shell bundle.
const CodeFile = lazy(() => import('../../code/CodeFile').then(({ CodeFile }) => ({ default: CodeFile })));

type Comments = ReturnType<typeof useFileComments>;
type LineSelection = ReturnType<typeof useLineSelection>;

/**
 * Not keyed by path, so the worker pool in CodeFile stays up while files change.
 */
export function FileRoute() {
    const { content, text, threads } = useLoaderData<FileView>();
    const { caller } = loaded(useRouteLoaderData<Me>('root'), 'root');
    const review = loaded(useRouteLoaderData<Review>('review'), 'review');
    const selection = useLineSelection(content.path, content.lineCount);
    const comments = useFileComments({ path: content.path, threads, caller, clearSelection: selection.clear });
    const commentable = 'open' === review.state;
    const fileThreads = useMemo(() => comments.threads.filter((thread) => null === thread.range), [comments.threads]);
    const fileComposer = null !== comments.composer && null === comments.composer.range ? comments.composer : null;
    const { select } = selection;
    const { open } = comments;
    const commentOnLines = useCallback(
        (range: LineRange) => {
            select(range);
            open(range);
        },
        [select, open],
    );

    return (
        <section className={styles.file} aria-label={content.path}>
            <FileHeader path={content.path}>
                {commentable && null !== text && null !== content.lineCount && <CommentOnLinesForm lineCount={content.lineCount} onSubmit={commentOnLines} />}
                {commentable && (
                    <Button size="small" onClick={() => open(null)}>
                        Comment on file
                    </Button>
                )}
            </FileHeader>
            {(fileThreads.length > 0 || null !== fileComposer) && (
                <div className={styles.fileThreads} data-testid="file-threads">
                    <ThreadList threads={fileThreads} caller={caller}>
                        {null !== fileComposer && <ComposerBox composer={fileComposer} onSubmit={comments.submit} onCancel={comments.cancel} />}
                    </ThreadList>
                </div>
            )}
            {null === text ? (
                <FilePlaceholder content={content} />
            ) : (
                <Code content={content} text={text} selection={selection} comments={comments} caller={caller} commentable={commentable} />
            )}
        </section>
    );
}

type CodeProps = {
    content: FileContent;
    text: string;
    selection: LineSelection;
    comments: Comments;
    caller: Actor;
    commentable: boolean;
};

function Code({ content, text, selection, comments, caller, commentable }: CodeProps) {
    const { composer, threads, open, submit, cancel } = comments;
    const composerLine = null !== composer && null !== composer.range ? bottomLine(composer.range) : null;
    const lines = useMemo(() => threadsByLine(threads, (thread) => (null === thread.range ? null : bottomLine(thread.range)), composerLine), [threads, composerLine]);
    // Kept per path: a line keeps its annotation index while its file is shown.
    const [annotated, setAnnotated] = useState<{ path: string; annotations: readonly CodeAnnotation[] }>({ path: content.path, annotations: NO_ANNOTATIONS });
    const currentAnnotations = stableAnnotations(annotated.path === content.path ? annotated.annotations : NO_ANNOTATIONS, lines.keys());

    if (annotated.path !== content.path || currentAnnotations !== annotated.annotations) {
        setAnnotated({ path: content.path, annotations: currentAnnotations });
    }

    const renderAnnotation = useCallback(
        (lineNumber: number) => {
            const line = lines.get(lineNumber);

            return undefined === line ? null : (
                <ThreadList threads={line.threads} caller={caller}>
                    {line.composer && null !== composer && <ComposerBox composer={composer} onSubmit={submit} onCancel={cancel} />}
                </ThreadList>
            );
        },
        [lines, caller, composer, submit, cancel],
    );
    const comment = useCallback((gesture: GestureRange) => open(lineRange(gesture.start, gesture.end)), [open]);

    return (
        <div className={styles.viewport} data-testid="code-view">
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
                    annotations={currentAnnotations}
                    renderAnnotation={renderAnnotation}
                    onComment={commentable ? comment : null}
                    className={styles.scroller}
                />
            </Suspense>
        </div>
    );
}

type ComposerBoxProps = {
    composer: Composer;
    onSubmit(body: string): boolean;
    onCancel(): void;
};

function ComposerBox({ composer, onSubmit, onCancel }: ComposerBoxProps) {
    return (
        <CommentBox
            key={composer.key}
            label={composerLabel(composer.range)}
            submitLabel="Add single comment"
            onSubmit={onSubmit}
            onCancel={onCancel}
            initialBody={composer.initialBody}
            error={composer.error}
            busy={false}
            focusOnMount
        />
    );
}

function composerLabel(range: LineRange | null): string {
    if (null === range) {
        return 'Comment on file';
    }

    return range.start === range.end ? `Comment on line ${range.start}` : `Comment on lines ${range.start} to ${range.end}`;
}

function loaded<T>(data: T | undefined, routeId: string): T {
    if (undefined === data) {
        throw new Error(`The file route renders below the ${routeId} route.`);
    }

    return data;
}
