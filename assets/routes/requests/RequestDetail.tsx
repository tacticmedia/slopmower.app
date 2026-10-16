import { CommentDiscussionIcon, FileDiffIcon } from '@primer/octicons-react';
import { Banner, Label, Link, RelativeTime, UnderlineNav } from '@primer/react';
import { useMemo } from 'react';
import { Link as RouterLink, useLoaderData, useRouteLoaderData, useSearchParams } from 'react-router';
import type { Actor } from '../../api/types/actor';
import type { Me } from '../../api/types/me';
import type { Request as ReviewRequest } from '../../api/types/request';
import { ThreadBox } from '../../comments/ThreadBox';
import { threadView } from '../../comments/threadView';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import { suggestionButtons } from '../../requests/allowedActions';
import { AnchorLink } from '../../requests/AnchorLink';
import { AnchorSnapshot } from '../../requests/AnchorSnapshot';
import { ClaimedBy } from '../../requests/ClaimedBy';
import { CommitList } from '../../requests/CommitList';
import { LocationInfo } from '../../requests/LocationInfo';
import { OutdatedLabel } from '../../requests/OutdatedLabel';
import { PullRequestStateLabel } from '../../requests/PullRequestStateLabel';
import { RequestActions } from '../../requests/RequestActions';
import { RequestTitle } from '../../requests/RequestTitle';
import shared from '../../requests/Requests.module.css';
import { CHANGES_TAB } from '../../requests/requestTabs';
import { StatusLabel } from '../../requests/StatusLabel';
import { SuggestionActions } from '../../requests/SuggestionActions';
import { SuggestionLabel } from '../../requests/SuggestionLabel';
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
    useDocumentTitle(request.summary);

    if (undefined === me) {
        throw new Error('The request detail renders inside the root route.');
    }

    return (
        <article className={styles.page} data-testid="request-detail" data-request-id={request.id}>
            <Link as={RouterLink} to={`/reviews/${request.reviewId}/requests`} className={styles.back}>
                Requests of this review
            </Link>
            <header className={styles.header}>
                <RequestTitle key={request.id} title={request.title} summary={request.summary} allowedActions={request.allowedActions} />
                <div className={styles.meta}>
                    <StatusLabel status={request.status} />
                    <OutdatedLabel outdated={request.outdated} />
                    <TurnLabel awaiting={request.awaiting} />
                    <SuggestionLabel pending={'pending' === request.suggestion} />
                    {request.requireApproval && (
                        <Label variant="accent" data-testid="plan-required">
                            Plan required
                        </Label>
                    )}
                    <AnchorLink reviewId={request.reviewId} anchor={request.anchor} />
                    {null !== request.anchor && null !== request.anchor.symbol && (
                        <code className={shared.location} data-testid="anchor-symbol">
                            {request.anchor.symbol.fqn}
                        </code>
                    )}
                    <span className={shared.muted}>
                        Opened by {request.author.name} <RelativeTime datetime={request.createdAt} />
                    </span>
                </div>
                {null !== request.anchor && <LocationInfo location={request.location} />}
                {'in_progress' === request.status && (
                    <p className={styles.claim}>
                        Claimed by <ClaimedBy claimedBy={request.claimedBy} claimedAt={request.claimedAt} />
                    </p>
                )}
                {request.pullRequests.length > 0 && (
                    <div className={styles.pullRequests} data-testid="pull-request-list">
                        <span id="pull-requests" className={shared.muted}>
                            Pull requests
                        </span>
                        <ul aria-labelledby="pull-requests" className={styles.pullRequestLinks}>
                            {request.pullRequests.map(({ url, state, title }) => (
                                <li key={url} className={styles.pullRequest}>
                                    <PullRequestStateLabel state={state} />
                                    <Link href={url} target="_blank" rel="noreferrer">
                                        {url}
                                    </Link>
                                    {null !== title && <span className={shared.muted}>{title}</span>}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                <RequestActions key={request.id} allowedActions={request.allowedActions} requireApproval={request.requireApproval} />
                <SuggestionActions key={`suggestion-${request.id}`} requestId={request.id} buttons={suggestionButtons(request.allowedActions)} />
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
            {null !== request.anchor && <AnchorSnapshot anchor={request.anchor} />}
            <section className={shared.section} aria-labelledby="conversation">
                <h2 id="conversation" className={shared.sectionTitle}>
                    Conversation
                </h2>
                <ThreadBox key={request.id} thread={thread} caller={caller} showHeader={false} detail />
            </section>
            <CommitList commits={request.commits} />
        </>
    );
}
