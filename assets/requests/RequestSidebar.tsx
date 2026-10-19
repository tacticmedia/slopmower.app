import { Button, Link } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { type ReactNode, useId } from 'react';
import { Link as RouterLink } from 'react-router';
import type { Request as ReviewRequest } from '../api/types/request';
import { AnchorLink } from './AnchorLink';
import { ClaimedBy } from './ClaimedBy';
import { LocationInfo } from './LocationInfo';
import { OutdatedLabel } from './OutdatedLabel';
import { PullRequestStateLabel } from './PullRequestStateLabel';
import shared from './Requests.module.css';
import styles from './RequestSidebar.module.css';
import { planState } from './requestStage';
import { CHANGES_TAB } from './requestTabs';
import { useRequestCommand } from './useRequestCommand';

/**
 * The metadata of the request detail, at the right of the conversation like the sidebar of a GitHub issue.
 */
export function RequestSidebar({ request, className }: { request: ReviewRequest; className: string | undefined }) {
    const { anchor } = request;

    return (
        <aside className={`${styles.sidebar} ${className}`} aria-label="Request details" data-testid="request-sidebar">
            <Section title="Assignee">
                {'in_progress' === request.status ? <ClaimedBy claimedBy={request.claimedBy} claimedAt={request.claimedAt} /> : <span className={shared.muted}>No one</span>}
            </Section>
            <PlanSection request={request} />
            <Section title="Location">
                <AnchorLink reviewId={request.reviewId} anchor={anchor} />
                {null !== anchor && null !== anchor.symbol && (
                    <code className={shared.location} data-testid="anchor-symbol">
                        {anchor.symbol.fqn}
                    </code>
                )}
                {null !== anchor && <LocationInfo location={request.location} showPath={false} />}
                <OutdatedLabel outdated={request.outdated} />
            </Section>
            <Section title="Development">
                <Development request={request} />
            </Section>
            {null !== request.suggestion && <Section title="Suggested by">{request.author.name}</Section>}
        </aside>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    const headingId = useId();

    return (
        <section className={styles.section} aria-labelledby={headingId}>
            <h2 id={headingId} className={styles.heading}>
                {title}
            </h2>
            {children}
        </section>
    );
}

function PlanSection({ request }: { request: ReviewRequest }) {
    const { busy, sent, error, send } = useRequestCommand<never>();
    const { requireApproval } = request;

    return (
        <Section title="Plan">
            <span data-testid="plan-state">{planState(request)}</span>
            {request.allowedActions.includes('set_plan_requirement') && (
                <Button size="small" onClick={() => send({ intent: 'set_plan_requirement', input: { requireApproval: !requireApproval } })} loading={'set_plan_requirement' === sent} disabled={busy}>
                    {requireApproval ? "Don't ask for a plan" : 'Ask for a plan first'}
                </Button>
            )}
            {null !== error && (
                <InlineMessage variant="critical" role="alert" data-testid="action-error">
                    {error}
                </InlineMessage>
            )}
        </Section>
    );
}

function Development({ request }: { request: ReviewRequest }) {
    const { pullRequests, commitCount } = request;

    if (0 === pullRequests.length && 0 === commitCount) {
        return <span className={shared.muted}>None yet</span>;
    }

    return (
        <>
            {pullRequests.length > 0 && (
                <ul aria-label="Pull requests" className={styles.list} data-testid="pull-request-list">
                    {pullRequests.map(({ url, state, title }) => (
                        <li key={url} className={styles.pullRequest}>
                            <PullRequestStateLabel state={state} />
                            <Link href={url} target="_blank" rel="noreferrer">
                                {url}
                            </Link>
                            {null !== title && <span className={shared.muted}>{title}</span>}
                        </li>
                    ))}
                </ul>
            )}
            {commitCount > 0 && (
                <Link as={RouterLink} to={`/requests/${request.id}?tab=${CHANGES_TAB}`} data-testid="linked-commits">
                    {commitCount} linked {1 === commitCount ? 'commit' : 'commits'}
                </Link>
            )}
        </>
    );
}
