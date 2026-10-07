import { CodeIcon, CommentDiscussionIcon } from '@primer/octicons-react';
import { Banner, Heading, Link, RelativeTime, UnderlineNav } from '@primer/react';
import { Outlet, Link as RouterLink, useLoaderData, useMatch } from 'react-router';
import type { Review } from '../../api/types/review';
import { AgentPromptSnippet } from '../../review/AgentPromptSnippet';
import { CarryOverDialog } from '../../review/CarryOverDialog';
import { CloseReviewDialog } from '../../review/CloseReviewDialog';
import { DeleteReviewDialog } from '../../review/DeleteReviewDialog';
import { FinishReviewOverlay } from '../../review/FinishReviewOverlay';
import { IndexProgress } from '../../review/IndexProgress';
import { QuickOpen } from '../../review/QuickOpen';
import { ReviewIdCopy } from '../../review/ReviewIdCopy';
import { shortSha } from '../../review/shortSha';
import styles from './ReviewLayout.module.css';
import type { ReviewData } from './reviewData';

function ClosedBanner({ review }: { review: Extract<Review, { state: 'closed' }> }) {
    return (
        <Banner
            className={styles.closed}
            variant="warning"
            title="This review is closed and read-only"
            data-testid="review-closed"
            description={
                <>
                    {review.closedBy.name} closed it <RelativeTime datetime={review.closedAt} />.
                    {null !== review.closeNote && <span className={styles.note}>{review.closeNote}</span>}
                </>
            }
        />
    );
}

function ReviewNavigation({ review }: { review: Review }) {
    const onRequests = null !== useMatch('/reviews/:reviewId/requests');

    return (
        <UnderlineNav aria-label="Review" variant="flush">
            <UnderlineNav.Item as={RouterLink} to={`/reviews/${review.id}`} leadingVisual={<CodeIcon />} aria-current={onRequests ? undefined : 'page'}>
                Code
            </UnderlineNav.Item>
            <UnderlineNav.Item
                as={RouterLink}
                to={`/reviews/${review.id}/requests`}
                leadingVisual={<CommentDiscussionIcon />}
                counter={review.counts.total}
                aria-current={onRequests ? 'page' : undefined}
            >
                Requests
            </UnderlineNav.Item>
        </UnderlineNav>
    );
}

export function ReviewLayout() {
    const { review, drafts } = useLoaderData<ReviewData>();
    // Every tab but Requests is the code browser.
    const codeBrowser = null === useMatch('/reviews/:reviewId/requests');

    return (
        <div className={styles.page} data-code-browser={codeBrowser ? '' : undefined}>
            <header className={styles.header}>
                <div className={styles.titleRow}>
                    <div className={styles.titleText}>
                        <Link as={RouterLink} to={`/repositories/${review.repositoryId}`} className={styles.repository}>
                            {review.repositoryName}
                        </Link>
                        <Heading as="h1">{review.title}</Heading>
                        <span className={styles.meta}>
                            <code>{review.ref}</code> at <code title={review.commitSha}>{shortSha(review.commitSha)}</code>, started by{' '}
                            {review.startedBy.name} <RelativeTime datetime={review.createdAt} />
                        </span>
                    </div>
                    <div className={styles.reviewActions}>
                        <QuickOpen reviewId={review.id} indexState={review.index.state} />
                        {'open' === review.state && drafts.length > 0 && <FinishReviewOverlay draftCount={drafts.length} />}
                        {'open' === review.state && <CarryOverDialog review={review} />}
                        {'open' === review.state && <CloseReviewDialog draftCount={drafts.length} />}
                        <DeleteReviewDialog repositoryId={review.repositoryId} />
                    </div>
                </div>
                <div className={styles.agent}>
                    <ReviewIdCopy reviewId={review.id} />
                    <AgentPromptSnippet reviewId={review.id} />
                </div>
                <IndexProgress index={review.index} />
                <ReviewNavigation review={review} />
            </header>
            {'closed' === review.state && <ClosedBanner review={review} />}
            <Outlet />
        </div>
    );
}
