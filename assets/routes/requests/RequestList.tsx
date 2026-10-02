import { Button, Checkbox, FormControl, Link, RelativeTime, SegmentedControl, TextInput } from '@primer/react';
import { type Column, DataTable, Table } from '@primer/react/experimental';
import type { FormEvent } from 'react';
import { Link as RouterLink, useLoaderData, useSearchParams } from 'react-router';
import { REQUEST_STATUSES, type RequestStatus } from '../../api/types/common';
import type { RequestPage } from '../../api/types/lists';
import type { RequestSummary } from '../../api/types/request-summary';
import { anchorHref, anchorLocation } from '../../requests/anchorLocation';
import { ClaimedBy } from '../../requests/ClaimedBy';
import shared from '../../requests/Requests.module.css';
import { StatusLabel } from '../../requests/StatusLabel';
import { STATUS_TEXT } from '../../requests/statusText';
import { TurnLabel } from '../../requests/TurnLabel';
import { requestFilters } from './requestData';
import styles from './RequestList.module.css';

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
        { id: 'status', header: 'Status', width: 'auto', renderCell: (request) => <StatusLabel status={request.status} /> },
        { id: 'turn', header: 'Turn', width: 'auto', renderCell: (request) => <TurnLabel awaiting={request.awaiting} /> },
        {
            id: 'location',
            header: 'Location',
            renderCell: (request) => (
                <Link as={RouterLink} to={anchorHref(reviewId, request.anchor)} className={shared.location}>
                    {anchorLocation(request.anchor)}
                </Link>
            ),
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
    const { reviewId, total, items } = useLoaderData<RequestPage>();
    const [searchParams, setSearchParams] = useSearchParams();
    const filters = requestFilters(searchParams);
    const setFilter = (name: 'status' | 'awaiting' | 'path', value: string | null): void => {
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
                <form className={styles.pathFilter} onSubmit={filterByPath} role="search">
                    <TextInput key={filters.path} name="path" defaultValue={filters.path} aria-label="Path prefix" placeholder="Path prefix" size="small" />
                    <Button type="submit" size="small">
                        Filter
                    </Button>
                </form>
            </div>
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
