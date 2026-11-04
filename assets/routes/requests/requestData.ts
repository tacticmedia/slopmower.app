import { type ActionFunctionArgs, type LoaderFunctionArgs, redirect } from 'react-router';
import { type ActionResult, actionError } from '../../api/actionResult';
import { apiGet, apiGetAllPages, apiWrite } from '../../api/client';
import { REQUEST_STATUSES, type RequestStatus, type Ulid } from '../../api/types/common';
import type { DraftList, RequestPage } from '../../api/types/lists';
import type { Request as ReviewRequest } from '../../api/types/request';
import type { RequestCommandResult } from '../../api/types/request-command-result';
import { removeSessionDraft } from '../../layout/useSessionDraft';
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
    set_plan_requirement: 'plan-requirement',
    accept_suggestion: 'accept-suggestion',
    dismiss_suggestion: 'dismiss-suggestion',
    set_title: 'title',
};

export const REQUEST_SORTS = ['updated', 'created', 'path'] as const;

export type RequestSort = (typeof REQUEST_SORTS)[number];

/**
 * The URL filters of the request list. Their names are the Q1 query names.
 */
export type RequestFilters = {
    /** `awaiting=none` is Closed. Any other `awaiting` lists the open requests. */
    closed: boolean;
    status: RequestStatus | null;
    /** `awaiting=user`. */
    waitingForYou: boolean;
    /** Q1 rejects a leading slash. */
    path: string;
    outdated: boolean;
    /** `suggestion=pending`. */
    suggestions: boolean;
    /** Null without the filter. */
    group: string | null;
    sort: RequestSort;
};

export function requestFilters(search: URLSearchParams): RequestFilters {
    const status = search.get('status');
    const sort = search.get('sort');
    const awaiting = search.get('awaiting');

    return {
        closed: 'none' === awaiting,
        status: REQUEST_STATUSES.find((known) => known === status) ?? null,
        waitingForYou: 'user' === awaiting,
        path: (search.get('path') ?? '').replace(/^\/+/, ''),
        outdated: 'true' === search.get('outdated'),
        suggestions: 'pending' === search.get('suggestion'),
        group: search.get('group') || null,
        sort: REQUEST_SORTS.find((known) => known === sort) ?? 'updated',
    };
}

/** The data of the request list route. */
export type RequestListData = RequestPage & {
    /** The summaries of the requests of reply drafts that the filters hide. */
    hiddenReplySummaries: Record<Ulid, string>;
};

export async function requestListLoader({ request, params }: LoaderFunctionArgs): Promise<RequestListData> {
    const { closed, status, waitingForYou, path, outdated, suggestions, group, sort } = requestFilters(new URL(request.url).searchParams);
    const awaiting = closed ? 'none' : waitingForYou ? 'user' : 'user,agent';
    const query = new URLSearchParams({ awaiting, sort, limit: String(REQUEST_PAGE_LIMIT) });
    if (null !== status) {
        query.set('status', status);
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
    if (null !== group) {
        query.set('group', group);
    }
    const review = `/api/v1/reviews/${encodedRouteParam(params, 'reviewId')}`;
    // Open and Closed always filter, so a reply draft can name a request that the page does not list.
    const [page, drafts] = await Promise.all([apiGetAllPages<RequestPage>(`${review}/requests?${query.toString()}`, request.signal), apiGet<DraftList>(`${review}/drafts`, request.signal)]);
    const listed = new Set(page.items.map(({ id }) => id));
    const hidden = new Set(drafts.items.flatMap((draft) => ('reply' === draft.kind && !listed.has(draft.requestId) ? [draft.requestId] : [])));
    // A summary only labels a draft, so a failed read keeps the list and the default label.
    const summaries = await Promise.all(
        [...hidden].map((id) => apiGet<ReviewRequest>(`/api/v1/requests/${encodeURIComponent(id)}`, request.signal).then(({ summary }): [Ulid, string][] => [[id, summary]], () => [])),
    );

    return { ...page, hiddenReplySummaries: Object.fromEntries(summaries.flat()) };
}

/** The data of the request detail route. */
export type RequestDetailData = ReviewRequest & {
    /** The oldest other request of the review that waits for the user. */
    nextRequestId: Ulid | null;
};

export async function requestLoader({ request, params }: LoaderFunctionArgs): Promise<RequestDetailData> {
    const detail = await apiGet<ReviewRequest>(`/api/v1/requests/${encodedRouteParam(params, 'requestId')}`, request.signal);

    return { ...detail, nextRequestId: await nextRequestId(detail, request.signal) };
}

async function nextRequestId({ id, reviewId }: Pick<ReviewRequest, 'id' | 'reviewId'>, signal: AbortSignal): Promise<Ulid | null> {
    const query = new URLSearchParams({ awaiting: 'user', sort: 'created', limit: '2' });
    const page = await apiGet<RequestPage>(`/api/v1/reviews/${encodeURIComponent(reviewId)}/requests?${query.toString()}`, signal);

    return page.items.find((summary) => summary.id !== id)?.id ?? null;
}

/**
 * A redirect skips the unsaved-comment guard, so `SplitDecisionButton` asks before it sends `next`. A write that
 * succeeds removes the session draft `draftKey` (D109).
 */
export async function requestAction({ request, params }: ActionFunctionArgs): Promise<ActionResult | Response> {
    const submitted = (await request.json()) as RequestIntent;
    const requestPath = `/api/v1/requests/${encodedRouteParam(params, 'requestId')}`;
    const path = 'reply' === submitted.intent ? `${requestPath}/comments` : `${requestPath}/${COMMAND_PATHS[submitted.intent]}`;

    try {
        const { request: written, events } = await apiWrite<RequestCommandResult>('POST', path, submitted.input, request.signal);
        rememberEvents(events);
        if (undefined !== submitted.draftKey) {
            removeSessionDraft(submitted.draftKey);
        }
        if (!('next' in submitted && true === submitted.next)) {
            return null;
        }
        const next = await nextRequestId(written, request.signal);

        return redirect(null === next ? `/reviews/${written.reviewId}/requests?awaiting=user` : `/requests/${next}`);
    } catch (error) {
        return actionError(error);
    }
}
