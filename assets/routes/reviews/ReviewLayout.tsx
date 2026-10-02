import { CodeIcon, CommentDiscussionIcon } from '@primer/octicons-react';
import { Banner, Heading, Link, RelativeTime, UnderlineNav } from '@primer/react';
import { type ActionFunctionArgs, type LoaderFunctionArgs, Outlet, Link as RouterLink, useLoaderData, useMatch } from 'react-router';
import { type ActionResult, optionalText, writeResult } from '../../api/actionResult';
import { apiGet, apiWrite } from '../../api/client';
import type { CloseReviewInput } from '../../api/types/inputs';
import type { Review } from '../../api/types/review';
import { AgentPromptSnippet } from '../../review/AgentPromptSnippet';
import { CloseReviewDialog } from '../../review/CloseReviewDialog';
import { IndexProgress } from '../../review/IndexProgress';
import { ReviewIdCopy } from '../../review/ReviewIdCopy';
import { shortSha } from '../../review/shortSha';
import { encodedRouteParam } from '../encodedRouteParam';
import styles from './ReviewLayout.module.css';

export function reviewLoader({ request, params }: LoaderFunctionArgs): Promise<Review> {
    return apiGet<Review>(`/api/v1/reviews/${encodedRouteParam(params, 'reviewId')}`, request.signal);
}

export async function reviewAction({ request, params }: ActionFunctionArgs): Promise<ActionResult> {
    const form = await request.formData();
    const input: CloseReviewInput = { note: optionalText(form.get('note')) };

    return writeResult(() => apiWrite('POST', `/api/v1/reviews/${encodedRouteParam(params, 'reviewId')}/close`, input));
}

function ClosedBanner({ review }: { review: Extract<Review, { state: 'closed' }> }) {
    return (
        <Banner
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
    const review = useLoaderData<Review>();

    return (
        <div className={styles.page}>
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
                    {'open' === review.state && <CloseReviewDialog />}
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
