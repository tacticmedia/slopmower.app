import { Link, RelativeTime } from '@primer/react';
import { ApiError } from '../../api/client';
import type { IssueCommits, IssueCommitsUnavailableReason } from '../../api/types/issue';
import sidebarStyles from '../../requests/RequestSidebar.module.css';
import { shortSha } from '../../review/shortSha';
import styles from './Issues.module.css';

const UNAVAILABLE_REASONS: Record<IssueCommitsUnavailableReason, string> = {
    no_server_copy: 'it has no copy on the server',
    cloning: 'it is being cloned',
    clone_failed: 'its clone failed',
    removed: 'its access is removed',
    read_failed: 'the server could not read its clone',
};

/** I12 (D132): the commits whose message or branch name has the issue key. */
export function IssueCommitList({ commits }: { commits: IssueCommits | ApiError }) {
    if (commits instanceof ApiError) {
        return <span className={styles.muted}>{commits.message}</span>;
    }

    return (
        <>
            {0 === commits.items.length ? (
                <span className={styles.muted}>No commits</span>
            ) : (
                <ul className={sidebarStyles.list} aria-label="Commits of the issue" data-testid="issue-commits">
                    {commits.items.map((commit) => (
                        <li key={`${commit.repository} ${commit.sha}`} className={styles.link}>
                            <span className={styles.linkTitle}>
                                {null === commit.htmlUrl ? (
                                    <code title={commit.sha}>{shortSha(commit.sha)}</code>
                                ) : (
                                    <Link href={commit.htmlUrl} target="_blank" rel="noreferrer">
                                        <code title={commit.sha}>{shortSha(commit.sha)}</code>
                                    </Link>
                                )}
                                {commit.subject}
                            </span>
                            <span className={styles.muted}>
                                {commit.repository} · {null === commit.branch ? 'On the default branch' : <code>{commit.branch}</code>} · <RelativeTime datetime={commit.committedAt} />
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {commits.unavailable.map(({ repository, reason }) => (
                <span key={repository} className={styles.muted} data-testid="issue-commits-unavailable">
                    The commits of {repository} are not listed: {UNAVAILABLE_REASONS[reason]}.
                </span>
            ))}
        </>
    );
}
