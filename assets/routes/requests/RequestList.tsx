import { Link } from '@primer/react';
import { useMemo } from 'react';
import { Link as RouterLink, useLoaderData, useRouteLoaderData } from 'react-router';
import type { Draft } from '../../api/types/draft';
import type { Me } from '../../api/types/me';
import type { DraftView } from '../../comments/draftView';
import { ThreadList } from '../../comments/ThreadList';
import type { ThreadView } from '../../comments/threadView';
import { anchorHref, anchorLocation } from '../../requests/anchorLocation';
import { RequestListHeader } from '../../requests/RequestListHeader';
import { RequestRow } from '../../requests/RequestRow';
import shared from '../../requests/Requests.module.css';
import type { ReviewData } from '../reviews/reviewData';
import type { RequestListData } from './requestData';
import styles from './RequestList.module.css';

const NO_THREADS: readonly ThreadView[] = [];

/**
 * Every pending draft of the caller. A reply names its request by its summary.
 */
function pendingDraftViews(reviewId: string, drafts: readonly Draft[], { items, hiddenReplySummaries }: RequestListData): DraftView[] {
    return drafts.map((draft) => {
        const view = { key: draft.id, draftId: draft.id, title: draft.title, body: draft.body, requireApproval: 'reply' === draft.kind ? null : draft.requireApproval };

        if ('reply' === draft.kind) {
            const summary = items.find(({ id }) => id === draft.requestId)?.summary ?? hiddenReplySummaries[draft.requestId];

            return { ...view, range: null, label: undefined === summary ? 'Reply to a request' : `Reply to ${summary}`, href: `/requests/${encodeURIComponent(draft.requestId)}` };
        }

        if (null === draft.anchor) {
            return { ...view, range: null, label: 'General', href: null };
        }

        const { range } = draft.anchor;

        return { ...view, range: null === range ? null : { start: range.startLine, end: range.endLine }, label: anchorLocation(draft.anchor), href: anchorHref(reviewId, draft.anchor) };
    });
}

export function RequestList() {
    const listData = useLoaderData<RequestListData>();
    const { reviewId, items } = listData;
    const me = useRouteLoaderData<Me>('root');
    const reviewData = useRouteLoaderData<ReviewData>('review');
    const drafts = reviewData?.drafts;
    const pendingDrafts = useMemo(() => pendingDraftViews(reviewId, drafts ?? [], listData), [reviewId, drafts, listData]);

    if (undefined === me || undefined === reviewData) {
        throw new Error('The request list renders below the review route.');
    }

    const commentable = 'open' === reviewData.review.state;

    return (
        <section className={styles.page}>
            {pendingDrafts.length > 0 && (
                <section className={shared.section} aria-labelledby="pending-drafts" data-testid="pending-drafts">
                    <h2 id="pending-drafts" className={shared.sectionTitle}>
                        Pending drafts
                    </h2>
                    <ThreadList threads={NO_THREADS} caller={me.caller} drafts={pendingDrafts} draftsEditable={commentable} />
                </section>
            )}
            <div className={styles.box}>
                <RequestListHeader byAwaiting={reviewData.review.counts.byAwaiting} drafts={commentable ? reviewData.drafts : null} />
                {0 === items.length ? (
                    <p className={styles.empty} data-testid="request-list-empty">
                        No request matches these filters.{' '}
                        <Link as={RouterLink} to={{ search: '' }}>
                            Clear the filters
                        </Link>
                    </p>
                ) : (
                    <ul className={styles.list} aria-label="Requests" data-testid="request-list">
                        {items.map((request) => (
                            <RequestRow key={request.id} request={request} />
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}
