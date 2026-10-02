import { CommentDiscussionIcon, FileDiffIcon } from '@primer/octicons-react';
import { Banner, Heading, Link, RelativeTime, UnderlineNav } from '@primer/react';
import { useMemo } from 'react';
import { Link as RouterLink, useLoaderData, useRouteLoaderData, useSearchParams } from 'react-router';
import type { Actor } from '../../api/types/actor';
import type { Me } from '../../api/types/me';
import type { Request as ReviewRequest } from '../../api/types/request';
import { ThreadBox } from '../../comments/ThreadBox';
import { threadView } from '../../comments/threadView';
import { AnchorSnapshot } from '../../requests/AnchorSnapshot';
import { anchorHref, anchorLocation } from '../../requests/anchorLocation';
import { ClaimedBy } from '../../requests/ClaimedBy';
import { CommitList } from '../../requests/CommitList';
import { LocationInfo } from '../../requests/LocationInfo';
import { OutdatedLabel } from '../../requests/OutdatedLabel';
import { RequestActions } from '../../requests/RequestActions';
import shared from '../../requests/Requests.module.css';
import { CHANGES_TAB } from '../../requests/requestTabs';
import { StatusLabel } from '../../requests/StatusLabel';
import { TurnLabel } from '../../requests/TurnLabel';
import styles from './RequestDetail.module.css';
import { RequestChanges } from './RequestChanges';

/**
 * A closed review gives an empty `allowedActions`, which hides the buttons and the reply box.
 */
export function RequestDetail() {
    const request = useLoaderData<ReviewRequest>();
    const me = useRouteLoaderData<Me>('root');
    const [searchParams] = useSearchParams();
    const onChanges = CHANGES_TAB === searchParams.get('tab');

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
                    <OutdatedLabel outdated={request.outdated} />
                    <TurnLabel awaiting={request.awaiting} />
                    <Link as={RouterLink} to={anchorHref(request.reviewId, request.anchor)} className={shared.location}>
                        {anchorLocation(request.anchor)}
                    </Link>
                    <span className={shared.muted}>
                        Opened by {request.author.name} <RelativeTime datetime={request.createdAt} />
                    </span>
                </div>
                <LocationInfo location={request.location} />
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
            <UnderlineNav aria-label="Request" variant="flush">
                <UnderlineNav.Item as={RouterLink} to={`/requests/${request.id}`} leadingVisual={<CommentDiscussionIcon />} aria-current={onChanges ? undefined : 'page'}>
                    Conversation
                </UnderlineNav.Item>
                <UnderlineNav.Item
                    as={RouterLink}
                    to={`/requests/${request.id}?tab=${CHANGES_TAB}`}
                    leadingVisual={<FileDiffIcon />}
                    counter={request.commitCount}
                    aria-current={onChanges ? 'page' : undefined}
                >
                    Changes
                </UnderlineNav.Item>
            </UnderlineNav>
            {onChanges ? <RequestChanges request={request} /> : <Conversation request={request} caller={me.caller} />}
        </article>
    );
}

function Conversation({ request, caller }: { request: ReviewRequest; caller: Actor }) {
    const thread = useMemo(() => threadView(request), [request]);

    return (
        <>
            <AnchorSnapshot anchor={request.anchor} />
            <section className={shared.section} aria-labelledby="conversation">
                <h2 id="conversation" className={shared.sectionTitle}>
                    Conversation
                </h2>
                <ThreadBox key={request.id} thread={thread} caller={caller} showHeader={false} />
            </section>
            <CommitList commits={request.commits} />
        </>
    );
}
