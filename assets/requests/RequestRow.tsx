import { CommentIcon, GitCommitIcon } from '@primer/octicons-react';
import { Link, RelativeTime } from '@primer/react';
import { Link as RouterLink } from 'react-router';
import type { RequestSummary } from '../api/types/request-summary';
import { AnchorLink } from './AnchorLink';
import { ClaimedBy } from './ClaimedBy';
import { OutdatedLabel } from './OutdatedLabel';
import { PlanToApproveLabel } from './PlanToApproveLabel';
import styles from './RequestRow.module.css';
import { StatusIcon } from './StatusIcon';
import { SuggestionLabel } from './SuggestionLabel';
import { WaitingForYouLabel } from './WaitingForYouLabel';

function plural(count: number, noun: string): string {
    return `${count} ${noun}${1 === count ? '' : 's'}`;
}

/** One request of the list, like an issue row of GitHub. */
export function RequestRow({ request }: { request: RequestSummary }) {
    return (
        <li className={styles.row} data-testid="request-row" data-request-id={request.id}>
            <span className={styles.icon}>
                <StatusIcon status={request.status} />
            </span>
            <div className={styles.body}>
                <div className={styles.headline}>
                    <Link as={RouterLink} to={`/requests/${request.id}`} className={styles.title}>
                        {request.summary}
                    </Link>
                    <span className={styles.labels}>
                        <WaitingForYouLabel waiting={'user' === request.awaiting} />
                        <PlanToApproveLabel pending={'pending' === request.proposal?.state} />
                        <SuggestionLabel pending={'pending' === request.suggestion} />
                        <OutdatedLabel outdated={request.outdated} />
                    </span>
                </div>
                <div className={styles.meta}>
                    <span className={styles.metaItem}>
                        <AnchorLink reviewId={request.reviewId} anchor={request.anchor} />
                    </span>
                    <span className={styles.metaItem}>
                        opened <RelativeTime datetime={request.createdAt} /> by {request.author.name}
                    </span>
                    {'in_progress' === request.status && (
                        <span className={styles.metaItem}>
                            claimed by <ClaimedBy claimedBy={request.claimedBy} claimedAt={request.claimedAt} />
                        </span>
                    )}
                </div>
            </div>
            <div className={styles.aside}>
                {request.commentCount > 0 && (
                    <span className={styles.count} title={plural(request.commentCount, 'comment')}>
                        <CommentIcon aria-label="Comments" />
                        {request.commentCount}
                    </span>
                )}
                {request.commitCount > 0 && (
                    <span className={styles.count} title={plural(request.commitCount, 'linked commit')}>
                        <GitCommitIcon aria-label="Linked commits" />
                        {request.commitCount}
                    </span>
                )}
                <span>
                    updated <RelativeTime datetime={request.updatedAt} />
                </span>
            </div>
        </li>
    );
}
