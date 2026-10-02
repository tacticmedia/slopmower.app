import { Banner, Heading, Link, RelativeTime } from '@primer/react';
import { useMemo } from 'react';
import { Link as RouterLink, useLoaderData, useRouteLoaderData } from 'react-router';
import type { Me } from '../../api/types/me';
import type { Request as ReviewRequest } from '../../api/types/request';
import { ThreadBox } from '../../comments/ThreadBox';
import { threadView } from '../../comments/threadView';
import { AnchorSnapshot } from '../../requests/AnchorSnapshot';
import { anchorHref, anchorLocation } from '../../requests/anchorLocation';
import { ClaimedBy } from '../../requests/ClaimedBy';
import { CommitList } from '../../requests/CommitList';
import { RequestActions } from '../../requests/RequestActions';
import shared from '../../requests/Requests.module.css';
import { StatusLabel } from '../../requests/StatusLabel';
import { TurnLabel } from '../../requests/TurnLabel';
import styles from './RequestDetail.module.css';

/**
 * A closed review gives an empty `allowedActions`, which hides the buttons and the reply box.
 */
export function RequestDetail() {
    const request = useLoaderData<ReviewRequest>();
    const me = useRouteLoaderData<Me>('root');
    const thread = useMemo(() => threadView(request), [request]);

    if (undefined === me) {
        throw new Error('The request detail renders inside the root route.');
    }

    return (
        <article className={styles.page} data-testid="request-detail" data-request-id={request.id}>
            <Link as={RouterLink} to={`/reviews/${request.reviewId}/requests`} className={styles.back}>
                Requests of this review
            </Link>
            <header className={styles.header}>
                <Heading as="h1" className={styles.title}>
                    {request.summary}
                </Heading>
                <div className={styles.meta}>
                    <StatusLabel status={request.status} />
                    <TurnLabel awaiting={request.awaiting} />
                    <Link as={RouterLink} to={anchorHref(request.reviewId, request.anchor)} className={shared.location}>
                        {anchorLocation(request.anchor)}
                    </Link>
                    <span className={shared.muted}>
                        Opened by {request.author.name} <RelativeTime datetime={request.createdAt} />
                    </span>
                </div>
                {'in_progress' === request.status && (
                    <p className={styles.claim}>
                        Claimed by <ClaimedBy claimedBy={request.claimedBy} claimedAt={request.claimedAt} />
                    </p>
                )}
                <RequestActions key={request.id} allowedActions={request.allowedActions} />
            </header>
            {'closed' === request.reviewState && (
                <Banner variant="warning" title="This review is closed and read-only" data-testid="review-closed" description="The request can be read, but not changed." />
            )}
            <AnchorSnapshot anchor={request.anchor} />
            <section className={shared.section} aria-labelledby="conversation">
                <h2 id="conversation" className={shared.sectionTitle}>
                    Conversation
                </h2>
                <ThreadBox key={request.id} thread={thread} caller={me.caller} showHeader={false} />
            </section>
            <CommitList commits={request.commits} />
        </article>
    );
}
