import { Label, RelativeTime, Spinner } from '@primer/react';
import { lazy, Suspense, useMemo } from 'react';
import type { CommitLink } from '../../api/types/commit-link';
import type { Request as ReviewRequest } from '../../api/types/request';
import { usePreference } from '../../layout/usePreference';
import { LocationInfo } from '../../requests/LocationInfo';
import shared from '../../requests/Requests.module.css';
import { shortSha } from '../../review/shortSha';
import { useCommitDiff } from './changesData';
import styles from './RequestChanges.module.css';

// A separate chunk, so the diff library loads with the first diff.
const CommitDiff = lazy(() => import('../../code/CommitDiff').then(({ CommitDiff }) => ({ default: CommitDiff })));

/**
 * The "Changes" tab: one section per linked commit, in link order.
 */
export function RequestChanges({ request }: { request: ReviewRequest }) {
    const { id, repositoryId, commits } = request;
    const located = null !== request.anchor;

    if (0 === commits.length) {
        return <p className={shared.muted}>No commit is linked yet.</p>;
    }

    return (
        <div className={styles.commits}>
            {commits.map((commit) => (
                <CommitChanges key={commit.commitSha} requestId={id} repositoryId={repositoryId} commit={commit} located={located} />
            ))}
        </div>
    );
}

function CommitChanges({ requestId, repositoryId, commit, located }: { requestId: string; repositoryId: string; commit: CommitLink; located: boolean }) {
    const titleId = `commit-${commit.commitSha}`;

    return (
        <section className={shared.section} aria-labelledby={titleId} data-testid="commit-changes" data-commit-sha={commit.commitSha}>
            <h2 id={titleId} className={styles.commitTitle}>
                <code title={commit.commitSha}>{shortSha(commit.commitSha)}</code>
                {'available' === commit.state ? <span className={shared.subject}>{commit.subject}</span> : <Label variant="attention">Pending</Label>}
            </h2>
            {'available' === commit.state ? (
                <AvailableCommit requestId={requestId} repositoryId={repositoryId} commitSha={commit.commitSha} located={located} />
            ) : (
                <p className={styles.note}>The server clone does not have this commit yet.</p>
            )}
        </section>
    );
}

function AvailableCommit({ requestId, repositoryId, commitSha, located }: { requestId: string; repositoryId: string; commitSha: string; located: boolean }) {
    const diff = useCommitDiff(requestId, commitSha);
    const [wrap] = usePreference('wrap');
    const files = useMemo(
        () => (undefined === diff || 'error' in diff ? [] : diff.files.toSorted((first, second) => Number(second.touchesAnchor) - Number(first.touchesAnchor))),
        [diff],
    );

    if (undefined === diff) {
        return (
            <p className={styles.loading}>
                <Spinner size="small" srText={null} /> Loading the changes
            </p>
        );
    }

    if ('error' in diff) {
        return (
            <p className={styles.error} role="alert">
                {diff.error}
            </p>
        );
    }

    return (
        <>
            <p className={styles.note}>
                {diff.authorName} committed <RelativeTime datetime={diff.committedAt} />
            </p>
            {located && <LocationInfo location={diff.location} />}
            {diff.truncated && <p className={styles.note}>Only the first {diff.files.length} changed files are shown.</p>}
            {0 === files.length ? (
                <p className={styles.note}>No file changed.</p>
            ) : (
                <Suspense
                    fallback={
                        <p className={styles.loading}>
                            <Spinner size="small" srText={null} /> Loading the changes
                        </p>
                    }
                >
                    <CommitDiff repositoryId={repositoryId} files={files} overflow={wrap ? 'wrap' : 'scroll'} />
                </Suspense>
            )}
        </>
    );
}
