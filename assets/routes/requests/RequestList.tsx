import { Button, Checkbox, FormControl, Label, Link, RelativeTime, SegmentedControl, TextInput } from '@primer/react';
import { type Column, DataTable, Table } from '@primer/react/experimental';
import { type FormEvent, useMemo } from 'react';
import { Link as RouterLink, useLoaderData, useRouteLoaderData, useSearchParams } from 'react-router';
import { REQUEST_STATUSES, type RequestStatus } from '../../api/types/common';
import type { Draft } from '../../api/types/draft';
import type { Me } from '../../api/types/me';
import type { RequestSummary } from '../../api/types/request-summary';
import type { DraftView } from '../../comments/draftView';
import { ThreadList } from '../../comments/ThreadList';
import type { ThreadView } from '../../comments/threadView';
import { AnchorLink } from '../../requests/AnchorLink';
import { anchorHref, anchorLocation } from '../../requests/anchorLocation';
import shared from '../../requests/Requests.module.css';
import { ClaimedBy } from '../../requests/ClaimedBy';
import { NewRequestBox } from '../../requests/NewRequestBox';
import { OutdatedLabel } from '../../requests/OutdatedLabel';
import { StatusLabel } from '../../requests/StatusLabel';
import { STATUS_TEXT } from '../../requests/statusText';
import { TurnLabel } from '../../requests/TurnLabel';
import type { ReviewData } from '../reviews/reviewData';
import { type RequestListData, requestFilters } from './requestData';
import styles from './RequestList.module.css';

const NO_THREADS: readonly ThreadView[] = [];

/**
 * Every pending draft of the caller. A reply names its request by its summary.
 */
function pendingDraftViews(reviewId: string, drafts: readonly Draft[], { items, hiddenReplySummaries }: RequestListData): DraftView[] {
    return drafts.map((draft) => {
        const view = { key: draft.id, draftId: draft.id, body: draft.body, requireApproval: 'reply' === draft.kind ? null : draft.requireApproval };

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

const STATUS_SEGMENTS: readonly { status: RequestStatus | null; label: string }[] = [
    { status: null, label: 'All' },
    ...REQUEST_STATUSES.map((status) => ({ status, label: STATUS_TEXT[status] })),
];

function columns(reviewId: string): Column<RequestSummary>[] {
    return [
        {
            id: 'summary',
            header: 'Request',
            rowHeader: true,
            renderCell: (request) => (
                <Link as={RouterLink} to={`/requests/${request.id}`}>
                    {request.summary}
                </Link>
            ),
        },
        {
            id: 'status',
            header: 'Status',
            width: 'auto',
            renderCell: (request) => (
                <span className={styles.labels}>
                    <StatusLabel status={request.status} />
                    <OutdatedLabel outdated={request.outdated} />
                    {'pending' === request.proposal?.state && (
                        <Label variant="attention" data-testid="plan-to-approve">
                            Plan to approve
                        </Label>
                    )}
                </span>
            ),
        },
        { id: 'turn', header: 'Turn', width: 'auto', renderCell: (request) => <TurnLabel awaiting={request.awaiting} /> },
        {
            id: 'location',
            header: 'Location',
            renderCell: (request) => <AnchorLink reviewId={reviewId} anchor={request.anchor} />,
        },
        {
            id: 'claimedBy',
            header: 'Claimed by',
            renderCell: (request) => ('in_progress' === request.status ? <ClaimedBy claimedBy={request.claimedBy} claimedAt={request.claimedAt} /> : null),
        },
        { id: 'updatedAt', header: 'Updated', width: 'auto', renderCell: (request) => <RelativeTime datetime={request.updatedAt} /> },
    ];
}

export function RequestList() {
    const listData = useLoaderData<RequestListData>();
    const { reviewId, total, items } = listData;
    const me = useRouteLoaderData<Me>('root');
    const reviewData = useRouteLoaderData<ReviewData>('review');
    const drafts = reviewData?.drafts;
    const pendingDrafts = useMemo(() => pendingDraftViews(reviewId, drafts ?? [], listData), [reviewId, drafts, listData]);
    const [searchParams, setSearchParams] = useSearchParams();
    const filters = requestFilters(searchParams);
    const setFilter = (name: 'status' | 'awaiting' | 'path' | 'outdated', value: string | null): void => {
        setSearchParams((current) => {
            const next = new URLSearchParams(current);
            if (null === value) {
                next.delete(name);
            } else {
                next.set(name, value);
            }

            return next;
        });
    };
    const filterByPath = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        const path = String(new FormData(event.currentTarget).get('path') ?? '').trim();
        setFilter('path', '' === path ? null : path);
    };

    if (undefined === me || undefined === reviewData) {
        throw new Error('The request list renders below the review route.');
    }

    const commentable = 'open' === reviewData.review.state;

    return (
        <section className={styles.page}>
            <div className={styles.filters} data-testid="request-filters">
                <SegmentedControl
                    aria-label="Status"
                    size="small"
                    variant={{ narrow: 'dropdown' }}
                    onChange={(index) => setFilter('status', STATUS_SEGMENTS[index]?.status ?? null)}
                >
                    {STATUS_SEGMENTS.map(({ status, label }) => (
                        <SegmentedControl.Button key={label} selected={status === filters.status}>
                            {label}
                        </SegmentedControl.Button>
                    ))}
                </SegmentedControl>
                <FormControl>
                    <Checkbox checked={filters.waitingForYou} onChange={(event) => setFilter('awaiting', event.target.checked ? 'user' : null)} />
                    <FormControl.Label>Waiting for you</FormControl.Label>
                </FormControl>
                <FormControl>
                    <Checkbox checked={filters.outdated} onChange={(event) => setFilter('outdated', event.target.checked ? 'true' : null)} />
                    <FormControl.Label>Outdated</FormControl.Label>
                </FormControl>
                <form className={styles.pathFilter} onSubmit={filterByPath} role="search">
                    <TextInput key={filters.path} name="path" defaultValue={filters.path} aria-label="Path prefix" placeholder="Path prefix" size="small" />
                    <Button type="submit" size="small">
                        Filter
                    </Button>
                </form>
                {commentable && <NewRequestBox drafts={reviewData.drafts} />}
            </div>
            {pendingDrafts.length > 0 && (
                <section className={shared.section} aria-labelledby="pending-drafts" data-testid="pending-drafts">
                    <h2 id="pending-drafts" className={shared.sectionTitle}>
                        Pending drafts
                    </h2>
                    <ThreadList threads={NO_THREADS} caller={me.caller} drafts={pendingDrafts} draftsEditable={commentable} />
                </section>
            )}
            <Table.Container data-testid="request-list">
                <Table.Title as="h2" id="requests">
                    Requests
                </Table.Title>
                <Table.Subtitle as="p" id="requests-total">
                    {0 === total ? 'No request matches these filters.' : `${total} ${1 === total ? 'request' : 'requests'}`}
                </Table.Subtitle>
                {items.length > 0 && <DataTable aria-labelledby="requests" aria-describedby="requests-total" data={items} columns={columns(reviewId)} />}
            </Table.Container>
        </section>
    );
}
