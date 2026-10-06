import { type FileContents, type FileDiffOptions, parseDiffFromFile } from '@pierre/diffs';
import { FileDiff, WorkerPoolContextProvider } from '@pierre/diffs/react';
import { Label, Spinner } from '@primer/react';
import { memo, useMemo } from 'react';
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

const STATUS_TEXT: Record<CommitDiffFileStatus, string> = {
    added: 'Added',
    modified: 'Modified',
    deleted: 'Deleted',
    renamed: 'Renamed',
    copied: 'Copied',
    type_changed: 'Type changed',
};

/**
 * The only wrapper around `FileDiff`. Each file loads its sides through R7 when it renders.
 */
export const CommitDiff = memo(function CommitDiff({ repositoryId, files, overflow }: { repositoryId: string; files: readonly CommitDiffFile[]; overflow: Overflow }) {
    return (
        <WorkerPoolContextProvider poolOptions={POOL_OPTIONS} highlighterOptions={HIGHLIGHTER_OPTIONS}>
            {files.map((file) => (
                <FileChange key={`${file.oldPath ?? ''}:${file.path}`} repositoryId={repositoryId} file={file} overflow={overflow} />
            ))}
        </WorkerPoolContextProvider>
    );
});

function FileChange({ repositoryId, file, overflow }: { repositoryId: string; file: CommitDiffFile; overflow: Overflow }) {
    const placeholder = diffPlaceholder(file);

    return (
        <section className={styles.file} data-testid="commit-diff-file" data-path={file.path} aria-label={file.path}>
            <header className={styles.header}>
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
            {null === placeholder ? <LoadedDiff repositoryId={repositoryId} file={file} overflow={overflow} /> : <p className={styles.placeholder}>{placeholder}</p>}
        </section>
    );
}

function LoadedDiff({ repositoryId, file, overflow }: { repositoryId: string; file: CommitDiffFile; overflow: Overflow }) {
    const sides = useDiffSides(repositoryId, file.oldBlobSha, file.newBlobSha);

    if (undefined === sides) {
        return (
            <p className={styles.placeholder}>
                <Spinner size="small" srText={null} /> Loading the diff
            </p>
        );
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
