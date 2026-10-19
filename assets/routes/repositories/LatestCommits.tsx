import { Button, RelativeTime } from '@primer/react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import type { ApiError } from '../../api/client';
import type { Commit } from '../../api/types/commit';
import type { DateTime } from '../../api/types/common';
import type { CommitLog } from '../../api/types/lists';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import { CopyButton } from '../../layout/CopyButton';
import { useSubmitOnce } from '../../layout/useSubmitOnce';
import { shortSha } from '../../review/shortSha';
import styles from './LatestCommits.module.css';

/** R9 of the default branch. A 409 or 422 of R9 gives `log: null` and the error. */
export type LatestCommitsData = { branch: string; log: CommitLog | null; error: ApiError | null };

type Props = { commits: LatestCommitsData; lastFetchedAt: DateTime | null };

export function LatestCommits({ commits: { branch, log, error }, lastFetchedAt }: Props) {
    return (
        <section className={styles.latest} aria-labelledby="latest-commits" data-testid="latest-commits">
            <h3 id="latest-commits" className={styles.title}>
                Latest commits on <code>{branch}</code>
            </h3>
            {null !== lastFetchedAt && (
                <p className={styles.meta}>
                    As of the last fetch <RelativeTime datetime={lastFetchedAt} />
                </p>
            )}
            {null === log ? (
                <p className={styles.notice}>{'repository_unavailable' === error?.code ? 'Commits show when the clone is ready.' : error?.message}</p>
            ) : (
                <ul className={styles.list}>
                    {log.items.map((commit) => (
                        <CommitRow key={commit.sha} commit={commit} />
                    ))}
                </ul>
            )}
        </section>
    );
}

/** The full SHA as the ref makes V1 skip the fetch. */
function CommitRow({ commit }: { commit: Commit }) {
    const start = useFetcher<ActionResult>();
    const submitOnce = useSubmitOnce(start.submit);
    const sha = shortSha(commit.sha);

    return (
        <li className={styles.row}>
            <div className={styles.body}>
                <span className={styles.subject} title={commit.subject}>
                    {commit.subject}
                </span>
                <span className={styles.meta}>
                    {commit.authorName} committed <RelativeTime datetime={commit.committedAt} />
                </span>
            </div>
            <div className={styles.aside}>
                <code title={commit.sha}>{sha}</code>
                <CopyButton text={commit.sha} label={`Copy the full SHA of ${sha}`} iconOnly />
                <start.Form method="post" onSubmit={submitOnce}>
                    <input type="hidden" name="intent" value="start" />
                    <input type="hidden" name="ref" value={commit.sha} />
                    <Button type="submit" size="small" aria-label={`Start review at ${sha}`} loading={'idle' !== start.state}>
                        Start review
                    </Button>
                </start.Form>
            </div>
            {start.data && (
                <div className={styles.error}>
                    <ActionErrorMessage result={start.data} />
                </div>
            )}
        </li>
    );
}
