import { CommentDiscussionIcon, FileDiffIcon } from '@primer/octicons-react';
import { Banner, Link, RelativeTime, UnderlineNav } from '@primer/react';
import { useMemo } from 'react';
import { Link as RouterLink, useLoaderData, useRouteLoaderData, useSearchParams } from 'react-router';
import type { Actor } from '../../api/types/actor';
import type { Me } from '../../api/types/me';
import type { Request as ReviewRequest } from '../../api/types/request';
import { ThreadBox } from '../../comments/ThreadBox';
import { threadView } from '../../comments/threadView';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import { ActionBar } from '../../requests/ActionBar';
import { AnchorSnapshot } from '../../requests/AnchorSnapshot';
import { CommitList } from '../../requests/CommitList';
import { RequestSidebar } from '../../requests/RequestSidebar';
import { RequestTitle } from '../../requests/RequestTitle';
import shared from '../../requests/Requests.module.css';
import { requestStage } from '../../requests/requestStage';
import { CHANGES_TAB } from '../../requests/requestTabs';
import { StatusLabel } from '../../requests/StatusLabel';
import styles from './RequestDetail.module.css';
import { RequestChanges } from './RequestChanges';

/**
 * The layout of a GitHub issue. A closed review gives an empty `allowedActions`, which hides the action bar, the plan
 * toggle, and the reply box.
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
            <header className={styles.header}>
                <Link as={RouterLink} to={`/reviews/${request.reviewId}/requests`} className={styles.back}>
                    Requests of this review
                </Link>
                <RequestTitle key={request.id} title={request.title} summary={request.summary} allowedActions={request.allowedActions} />
                <p className={styles.state}>
                    <StatusLabel status={request.status} />
                    <span className={shared.muted}>
                        {request.author.name} opened this <RelativeTime datetime={request.createdAt} /> · {request.commentCount} {1 === request.commentCount ? 'comment' : 'comments'}
                    </span>
                </p>
            </header>
            <ActionBar id={request.id} status={request.status} summary={request.summary} allowedActions={request.allowedActions} />
            <p className={'user' === request.awaiting ? `${styles.nextStep} ${styles.yourTurn}` : styles.nextStep} data-testid="next-step" data-awaiting={request.awaiting}>
                {requestStage(request)}
            </p>
            {'closed' === request.reviewState && (
                <Banner variant="warning" title="This review is closed and read-only" data-testid="review-closed" description="The request can be read, but not changed." />
            )}
            <div className={styles.layout}>
                <RequestSidebar request={request} className={styles.sidebar} />
                <UnderlineNav aria-label="Request" variant="flush" className={styles.tabs}>
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
                <div className={styles.main}>{onChanges ? <RequestChanges request={request} /> : <Conversation request={request} caller={me.caller} />}</div>
            </div>
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
