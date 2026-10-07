import { Label, Link, RelativeTime } from '@primer/react';
import { Link as RouterLink } from 'react-router';
import type { CommitLink } from '../api/types/commit-link';
import { shortSha } from '../review/shortSha';
import styles from './Requests.module.css';
import { CHANGES_TAB, commitSectionId } from './requestTabs';

/**
 * The commit note shows in the thread, as the body of its `commit.linked` entry. An available commit links to its
 * section on the "Changes" tab.
 */
export function CommitList({ commits }: { commits: CommitLink[] }) {
    return (
        <section className={styles.section} aria-labelledby="linked-commits" data-testid="commit-list">
            <h2 id="linked-commits" className={styles.sectionTitle}>
                Linked commits
            </h2>
            {0 === commits.length ? (
                <p className={styles.muted}>No commit is linked yet.</p>
            ) : (
                <ul className={styles.commits}>
                    {commits.map((commit) => (
                        <li key={commit.commitSha} className={styles.commit} data-testid="commit-link" data-state={commit.state}>
                            <div className={styles.commitTitle}>
                                <code title={commit.commitSha}>{shortSha(commit.commitSha)}</code>
                                {'available' === commit.state ? (
                                    <>
                                        <Label variant="success">Available</Label>
                                        <Link as={RouterLink} to={`?tab=${CHANGES_TAB}#${commitSectionId(commit.commitSha)}`} className={styles.subject}>
                                            {commit.subject}
                                        </Link>
                                    </>
                                ) : (
                                    <>
                                        <Label variant="attention">Pending</Label>
                                        <span className={styles.muted}>The server clone does not have this commit yet.</span>
                                    </>
                                )}
                            </div>
                            <span className={styles.muted}>
                                Linked by {commit.linkedBy.name} <RelativeTime datetime={commit.linkedAt} />
                            </span>
                            {commit.otherRequestIds.length > 0 && (
                                <span className={styles.muted}>
                                    Also linked to{' '}
                                    {commit.otherRequestIds.map((requestId, index) => (
                                        <span key={requestId}>
                                            {index > 0 && ', '}
                                            <Link as={RouterLink} to={`/requests/${requestId}`}>
                                                <code>{requestId}</code>
                                            </Link>
                                        </span>
                                    ))}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
