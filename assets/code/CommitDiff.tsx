import { type FileContents, type FileDiffOptions, parseDiffFromFile } from '@pierre/diffs';
import { FileDiff, WorkerPoolContextProvider } from '@pierre/diffs/react';
import type { WorkerInitializationRenderOptions, WorkerPoolOptions } from '@pierre/diffs/worker';
import diffsWorkerUrl from '@pierre/diffs/worker/worker.js?worker&url';
import { Label, Spinner } from '@primer/react';
import { memo, useMemo } from 'react';
import type { CommitDiffFile, CommitDiffFileStatus } from '../api/types/commit-diff';
import { type DiffSides, useDiffSides } from '../routes/requests/changesData';
import styles from './CommitDiff.module.css';
import { diffPlaceholder } from './diffPlaceholder';

const THEME = { light: 'github-light', dark: 'github-dark' } as const;

const POOL_OPTIONS: WorkerPoolOptions = {
    workerFactory: () => new Worker(diffsWorkerUrl, { type: 'module' }),
    poolSize: 2,
};

const HIGHLIGHTER_OPTIONS: WorkerInitializationRenderOptions = { theme: THEME };

const DIFF_OPTIONS = {
    theme: THEME,
    diffStyle: 'unified',
    disableFileHeader: true,
} as const satisfies FileDiffOptions<undefined, undefined>;

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
export const CommitDiff = memo(function CommitDiff({ repositoryId, files }: { repositoryId: string; files: readonly CommitDiffFile[] }) {
    return (
        <WorkerPoolContextProvider poolOptions={POOL_OPTIONS} highlighterOptions={HIGHLIGHTER_OPTIONS}>
            {files.map((file) => (
                <FileChange key={`${file.oldPath ?? ''}:${file.path}`} repositoryId={repositoryId} file={file} />
            ))}
        </WorkerPoolContextProvider>
    );
});

function FileChange({ repositoryId, file }: { repositoryId: string; file: CommitDiffFile }) {
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
            {null === placeholder ? <LoadedDiff repositoryId={repositoryId} file={file} /> : <p className={styles.placeholder}>{placeholder}</p>}
        </section>
    );
}

function LoadedDiff({ repositoryId, file }: { repositoryId: string; file: CommitDiffFile }) {
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

    return <SidesDiff file={file} sides={sides} />;
}

const SidesDiff = memo(function SidesDiff({ file, sides }: { file: CommitDiffFile; sides: DiffSides }) {
    const fileDiff = useMemo(() => {
        const oldName = file.oldPath ?? file.path;
        // With the path, the highlight cache key, as in CodeFile.
        const oldFile: FileContents | null = null === sides.old ? null : { name: oldName, contents: sides.old, cacheKey: `${file.oldBlobSha}:${oldName}` };
        const newFile: FileContents | null = null === sides.new ? null : { name: file.path, contents: sides.new, cacheKey: `${file.newBlobSha}:${file.path}` };

        return parseDiffFromFile(oldFile, newFile);
    }, [file, sides]);

    return <FileDiff fileDiff={fileDiff} options={DIFF_OPTIONS} />;
});
