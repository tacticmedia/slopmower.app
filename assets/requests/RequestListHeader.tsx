import { CheckIcon, IssueOpenedIcon } from '@primer/octicons-react';
import { ActionList, ActionMenu, Button, CounterLabel, Link, TextInput } from '@primer/react';
import type { FormEvent } from 'react';
import { Link as RouterLink, useSearchParams } from 'react-router';
import { REQUEST_STATUSES, type RequestStatus } from '../api/types/common';
import type { Draft } from '../api/types/draft';
import type { RequestCounts } from '../api/types/review';
import { type RequestSort, requestFilters } from '../routes/requests/requestData';
import { NewRequestBox } from './NewRequestBox';
import styles from './RequestListHeader.module.css';
import { STATUS_TEXT } from './statusText';

type Changes = Partial<Record<'awaiting' | 'status' | 'path' | 'outdated' | 'suggestion' | 'sort', string | null>>;

const STATUS_OPTIONS: readonly { status: RequestStatus | null; label: string }[] = [{ status: null, label: 'All' }, ...REQUEST_STATUSES.map((status) => ({ status, label: STATUS_TEXT[status] }))];

/** `updated` is the default, so it leaves the URL. */
const SORT_OPTIONS: readonly { sort: RequestSort; label: string }[] = [
    { sort: 'updated', label: 'Recently updated' },
    { sort: 'created', label: 'Oldest' },
    { sort: 'path', label: 'Path' },
];

function changedSearch(current: URLSearchParams, changes: Changes): URLSearchParams {
    const next = new URLSearchParams(current);
    for (const [name, value] of Object.entries(changes)) {
        if ('string' === typeof value) {
            next.set(name, value);
        } else {
            next.delete(name);
        }
    }

    return next;
}

type Props = {
    /** The review totals, independent of the filters. */
    byAwaiting: RequestCounts['byAwaiting'];
    /** Null when the review is closed. */
    drafts: readonly Draft[] | null;
};

/** Every filter is a URL search parameter with its Q1 name. */
export function RequestListHeader({ byAwaiting, drafts }: Props) {
    const [searchParams, setSearchParams] = useSearchParams();
    const filters = requestFilters(searchParams);
    const change = (changes: Changes): void => setSearchParams((current) => changedSearch(current, changes));
    const filterByPath = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        const path = String(new FormData(event.currentTarget).get('path') ?? '').trim();
        change({ path: '' === path ? null : path });
    };

    return (
        <div className={styles.header} data-testid="request-filters">
            <div className={styles.states}>
                <Link as={RouterLink} to={{ search: changedSearch(searchParams, { awaiting: null }).toString() }} aria-current={filters.closed ? undefined : 'true'} className={styles.state}>
                    <IssueOpenedIcon /> Open <CounterLabel>{byAwaiting.user + byAwaiting.agent}</CounterLabel>
                </Link>
                <Link as={RouterLink} to={{ search: changedSearch(searchParams, { awaiting: 'none' }).toString() }} aria-current={filters.closed ? 'true' : undefined} className={styles.state}>
                    <CheckIcon /> Closed <CounterLabel>{byAwaiting.none}</CounterLabel>
                </Link>
                {!filters.closed && (
                    <Button size="small" variant="invisible" aria-pressed={filters.waitingForYou} className={styles.toggle} onClick={() => change({ awaiting: filters.waitingForYou ? null : 'user' })}>
                        Waiting for you
                    </Button>
                )}
            </div>
            <div className={styles.tools}>
                <ActionMenu>
                    <ActionMenu.Button size="small">Status</ActionMenu.Button>
                    <ActionMenu.Overlay>
                        <ActionList selectionVariant="single">
                            {STATUS_OPTIONS.map(({ status, label }) => (
                                <ActionList.Item key={label} selected={status === filters.status} onSelect={() => change({ status })}>
                                    {label}
                                </ActionList.Item>
                            ))}
                        </ActionList>
                    </ActionMenu.Overlay>
                </ActionMenu>
                <ActionMenu>
                    <ActionMenu.Button size="small">Filters</ActionMenu.Button>
                    <ActionMenu.Overlay>
                        <ActionList selectionVariant="multiple">
                            <ActionList.Item selected={filters.outdated} onSelect={() => change({ outdated: filters.outdated ? null : 'true' })}>
                                Outdated
                            </ActionList.Item>
                            <ActionList.Item selected={filters.suggestions} onSelect={() => change({ suggestion: filters.suggestions ? null : 'pending' })}>
                                Suggestions
                            </ActionList.Item>
                        </ActionList>
                    </ActionMenu.Overlay>
                </ActionMenu>
                <ActionMenu>
                    <ActionMenu.Button size="small">Sort</ActionMenu.Button>
                    <ActionMenu.Overlay>
                        <ActionList selectionVariant="single">
                            {SORT_OPTIONS.map(({ sort, label }) => (
                                <ActionList.Item key={sort} selected={sort === filters.sort} onSelect={() => change({ sort: 'updated' === sort ? null : sort })}>
                                    {label}
                                </ActionList.Item>
                            ))}
                        </ActionList>
                    </ActionMenu.Overlay>
                </ActionMenu>
                <form className={styles.pathFilter} onSubmit={filterByPath} role="search">
                    <TextInput key={filters.path} name="path" defaultValue={filters.path} aria-label="Path prefix" placeholder="Path prefix" size="small" className={styles.pathInput} />
                    <Button type="submit" size="small">
                        Filter
                    </Button>
                </form>
            </div>
            {null !== drafts && <NewRequestBox drafts={drafts} />}
        </div>
    );
}
