import type { ActionFunctionArgs, LoaderFunctionArgs } from 'react-router';
import { type ActionResult, writeResult } from '../../api/actionResult';
import { apiGet, apiGetAllPages, apiWrite } from '../../api/client';
import { REQUEST_STATUSES, type RequestStatus, type Ulid } from '../../api/types/common';
import type { DraftList, RequestPage } from '../../api/types/lists';
import type { Request as ReviewRequest } from '../../api/types/request';
import type { RequestCommandResult } from '../../api/types/request-command-result';
import { rememberEvents } from '../../live/eventPositions';
import type { RequestCommandIntent, RequestIntent } from '../../requests/requestIntent';
import { encodedRouteParam } from '../encodedRouteParam';

export const REQUEST_PAGE_LIMIT = 200;

const COMMAND_PATHS: Record<RequestCommandIntent['intent'], string> = {
    verify: 'verify',
    reopen: 'reopen',
    mark_wont_do: 'wont-do',
    release: 'release',
    approve_proposal: 'approve-proposal',
    reject_proposal: 'reject-proposal',
    set_plan_requirement: 'plan-requirement',
    accept_suggestion: 'accept-suggestion',
    dismiss_suggestion: 'dismiss-suggestion',
};

/**
 * The URL filters of the request list. Their names are the Q1 query names.
 */
export type RequestFilters = {
    status: RequestStatus | null;
    waitingForYou: boolean;
    /** Q1 rejects a leading slash. */
    path: string;
    outdated: boolean;
    /** `suggestion=pending`. */
    suggestions: boolean;
};

export function requestFilters(search: URLSearchParams): RequestFilters {
    const status = search.get('status');

    return {
        status: REQUEST_STATUSES.find((known) => known === status) ?? null,
        waitingForYou: 'user' === search.get('awaiting'),
        path: (search.get('path') ?? '').replace(/^\/+/, ''),
        outdated: 'true' === search.get('outdated'),
        suggestions: 'pending' === search.get('suggestion'),
    };
}

/** The data of the request list route. */
export type RequestListData = RequestPage & {
    /** The summaries of the requests of reply drafts that the filters hide. */
    hiddenReplySummaries: Record<Ulid, string>;
};

/**
 * Most recently updated first, so the agent's latest work is on top.
 */
export async function requestListLoader({ request, params }: LoaderFunctionArgs): Promise<RequestListData> {
    const { status, waitingForYou, path, outdated, suggestions } = requestFilters(new URL(request.url).searchParams);
    const query = new URLSearchParams({ sort: 'updated', limit: String(REQUEST_PAGE_LIMIT) });
    if (null !== status) {
        query.set('status', status);
    }
    if (waitingForYou) {
        query.set('awaiting', 'user');
    }
    if ('' !== path) {
        query.set('path', path);
    }
    if (outdated) {
        query.set('outdated', 'true');
    }
    if (suggestions) {
        query.set('suggestion', 'pending');
    }
    const review = `/api/v1/reviews/${encodedRouteParam(params, 'reviewId')}`;
    const filtered = null !== status || waitingForYou || '' !== path || outdated || suggestions;
    const [page, drafts] = await Promise.all([
        apiGetAllPages<RequestPage>(`${review}/requests?${query.toString()}`, request.signal),
        filtered ? apiGet<DraftList>(`${review}/drafts`, request.signal) : null,
    ]);
    const listed = new Set(page.items.map(({ id }) => id));
    const hidden = new Set(drafts?.items.flatMap((draft) => ('reply' === draft.kind && !listed.has(draft.requestId) ? [draft.requestId] : [])));
    // A summary only labels a draft, so a failed read keeps the list and the default label.
    const summaries = await Promise.all(
        [...hidden].map((id) => apiGet<ReviewRequest>(`/api/v1/requests/${encodeURIComponent(id)}`, request.signal).then(({ summary }): [Ulid, string][] => [[id, summary]], () => [])),
    );

    return { ...page, hiddenReplySummaries: Object.fromEntries(summaries.flat()) };
}

export function requestLoader({ request, params }: LoaderFunctionArgs): Promise<ReviewRequest> {
    return apiGet<ReviewRequest>(`/api/v1/requests/${encodedRouteParam(params, 'requestId')}`, request.signal);
}

export async function requestAction({ request, params }: ActionFunctionArgs): Promise<ActionResult> {
    const submitted = (await request.json()) as RequestIntent;
    const requestPath = `/api/v1/requests/${encodedRouteParam(params, 'requestId')}`;
    const path = 'reply' === submitted.intent ? `${requestPath}/comments` : `${requestPath}/${COMMAND_PATHS[submitted.intent]}`;

    return writeResult(async () => rememberEvents((await apiWrite<RequestCommandResult>('POST', path, submitted.input, request.signal)).events));
}
