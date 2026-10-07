import { type FileContents, type FileDiffOptions, parseDiffFromFile, parsePatchFiles } from '@pierre/diffs';
import { FileDiff, WorkerPoolContextProvider } from '@pierre/diffs/react';
import { ChevronDownIcon, ChevronRightIcon } from '@primer/octicons-react';
import { IconButton, Label, Spinner } from '@primer/react';
import { memo, type RefObject, useEffect, useMemo, useRef, useState } from 'react';
import type { CommitDiffFile, CommitDiffFileStatus } from '../api/types/commit-diff';
import { type DiffSides, useDiffSides } from '../routes/requests/changesData';
import type { Overflow } from './CodeFile';
import styles from './CommitDiff.module.css';
import { diffPlaceholder } from './diffPlaceholder';
import { HIGHLIGHTER_OPTIONS, POOL_OPTIONS, THEME } from './workerPool';

const BASE_DIFF_OPTIONS = {
    theme: THEME,
    diffStyle: 'unified',
    disableFileHeader: true,
} as const satisfies FileDiffOptions<undefined, undefined>;

const DIFF_OPTIONS: Record<Overflow, FileDiffOptions<undefined, undefined>> = {
    scroll: { ...BASE_DIFF_OPTIONS, overflow: 'scroll' },
    wrap: { ...BASE_DIFF_OPTIONS, overflow: 'wrap' },
};

// A patch holds no unchanged lines to expand, so its separators are the `@@` hunk headers.
const PROPOSAL_DIFF_OPTIONS: Record<Overflow, FileDiffOptions<undefined, undefined>> = {
    scroll: { ...DIFF_OPTIONS.scroll, hunkSeparators: 'metadata' },
    wrap: { ...DIFF_OPTIONS.wrap, hunkSeparators: 'metadata' },
};

const STATUS_TEXT: Record<CommitDiffFileStatus, string> = {
    added: 'Added',
    modified: 'Modified',
    deleted: 'Deleted',
    renamed: 'Renamed',
    copied: 'Copied',
    type_changed: 'Type changed',
};

/** Above this many files, only the files that touch the anchor start expanded. */
const COLLAPSE_ABOVE_FILES = 20;

/** The default `--diffs-line-height` of `@pierre/diffs`. */
const DIFF_LINE_HEIGHT_PX = 20;

/** A file starts to load this far outside the window. */
const LOAD_AHEAD_MARGIN = '200px';

/**
 * A file of a linked commit loads its sides through R7 once it is expanded and near the window.
 */
export const CommitDiff = memo(function CommitDiff({ repositoryId, files, overflow }: { repositoryId: string; files: readonly CommitDiffFile[]; overflow: Overflow }) {
    const collapsed = files.length > COLLAPSE_ABOVE_FILES;

    return (
        <WorkerPoolContextProvider poolOptions={POOL_OPTIONS} highlighterOptions={HIGHLIGHTER_OPTIONS}>
            {files.map((file) => (
                <FileChange key={`${file.oldPath ?? ''}:${file.path}`} repositoryId={repositoryId} file={file} overflow={overflow} initiallyExpanded={!collapsed || file.touchesAnchor} />
            ))}
        </WorkerPoolContextProvider>
    );
});

/**
 * A proposed unified diff, one `FileDiff` per file. The patch holds the changed lines, so nothing loads per file.
 */
export const ProposalDiff = memo(function ProposalDiff({ diff, cacheKey, overflow }: { diff: string; cacheKey: string; overflow: Overflow }) {
    const files = useMemo(() => parsePatchFiles(diff, cacheKey).flatMap(({ files }) => files), [diff, cacheKey]);

    return (
        <WorkerPoolContextProvider poolOptions={POOL_OPTIONS} highlighterOptions={HIGHLIGHTER_OPTIONS}>
            {files.map((file) => (
                <section key={`${file.prevName ?? ''}:${file.name}`} className={styles.file} data-testid="proposal-diff-file" data-path={file.name} aria-label={file.name}>
                    <header className={styles.header}>
                        <h3 className={styles.path}>{undefined === file.prevName ? file.name : `${file.prevName} to ${file.name}`}</h3>
                    </header>
                    <div className={styles.body}>
                        <FileDiff fileDiff={file} options={PROPOSAL_DIFF_OPTIONS[overflow]} />
                    </div>
                </section>
            ))}
        </WorkerPoolContextProvider>
    );
});

type FileChangeProps = { repositoryId: string; file: CommitDiffFile; overflow: Overflow; initiallyExpanded: boolean };

function FileChange({ repositoryId, file, overflow, initiallyExpanded }: FileChangeProps) {
    const placeholder = diffPlaceholder(file);
    const [expanded, setExpanded] = useState(initiallyExpanded);
    const near = useNearWindow(expanded && null === placeholder);
    const changedLines = (file.additions ?? 0) + (file.deletions ?? 0);
    // Until the diff renders, the file takes about the height of its changed lines, so the files below stay away.
    const pendingHeight = `${Math.max(changedLines, 1) * DIFF_LINE_HEIGHT_PX}px`;

    return (
        <section ref={near.ref} className={styles.file} data-testid="commit-diff-file" data-path={file.path} aria-label={file.path}>
            <header className={styles.header}>
                <IconButton icon={expanded ? ChevronDownIcon : ChevronRightIcon} aria-label="Toggle the diff" aria-expanded={expanded} variant="invisible" size="small" onClick={() => setExpanded(!expanded)} />
                <h3 className={styles.path}>{null === file.oldPath ? file.path : `${file.oldPath} to ${file.path}`}</h3>
                <Label variant="secondary">{STATUS_TEXT[file.status]}</Label>
                {file.touchesAnchor && (
                    <Label variant="accent" data-testid="touches-anchor">
                        Anchored here
                    </Label>
                )}
                {null !== file.additions && null !== file.deletions && (
                    <span className={styles.counts}>
                        <span className={styles.additions}>+{file.additions}</span> <span className={styles.deletions}>-{file.deletions}</span>
                    </span>
                )}
            </header>
            <div className={styles.body} hidden={!expanded}>
                {null !== placeholder ? (
                    <p className={styles.placeholder}>{placeholder}</p>
                ) : near.reached ? (
                    <LoadedDiff repositoryId={repositoryId} file={file} overflow={overflow} pendingHeight={pendingHeight} />
                ) : (
                    <PendingDiff height={pendingHeight} />
                )}
            </div>
        </section>
    );
}

/**
 * `reached` turns true when the element comes near the window while `watching` is true, and stays true.
 */
function useNearWindow(watching: boolean): { ref: RefObject<HTMLElement | null>; reached: boolean } {
    const ref = useRef<HTMLElement>(null);
    const [reached, setReached] = useState(false);

    useEffect(() => {
        const element = ref.current;
        if (!watching || reached || null === element) {
            return;
        }
        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    setReached(true);
                }
            },
            { rootMargin: LOAD_AHEAD_MARGIN },
        );
        observer.observe(element);

        return () => observer.disconnect();
    }, [watching, reached]);

    return { ref, reached };
}

function PendingDiff({ height }: { height: string }) {
    return (
        <p className={styles.placeholder} style={{ minHeight: height }}>
            <Spinner size="small" srText={null} /> Loading the diff
        </p>
    );
}

function LoadedDiff({ repositoryId, file, overflow, pendingHeight }: { repositoryId: string; file: CommitDiffFile; overflow: Overflow; pendingHeight: string }) {
    const sides = useDiffSides(repositoryId, file.oldBlobSha, file.newBlobSha);

    if (undefined === sides) {
        return <PendingDiff height={pendingHeight} />;
    }

    if ('error' in sides) {
        return (
            <p className={styles.placeholder} role="alert">
                {sides.error}
            </p>
        );
    }

    return <SidesDiff file={file} sides={sides} overflow={overflow} />;
}

const SidesDiff = memo(function SidesDiff({ file, sides, overflow }: { file: CommitDiffFile; sides: DiffSides; overflow: Overflow }) {
    const fileDiff = useMemo(() => {
        const oldName = file.oldPath ?? file.path;
        // With the path, the highlight cache key, as in CodeFile.
        const oldFile: FileContents | null = null === sides.old ? null : { name: oldName, contents: sides.old, cacheKey: `${file.oldBlobSha}:${oldName}` };
        const newFile: FileContents | null = null === sides.new ? null : { name: file.path, contents: sides.new, cacheKey: `${file.newBlobSha}:${file.path}` };

        return parseDiffFromFile(oldFile, newFile);
    }, [file, sides]);

    return <FileDiff fileDiff={fileDiff} options={DIFF_OPTIONS[overflow]} />;
});
