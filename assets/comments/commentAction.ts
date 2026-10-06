import type { ActionFunctionArgs } from 'react-router';
import { type ActionResult, writeResult } from '../api/actionResult';
import { apiWrite } from '../api/client';
import type { RequestCommandResult } from '../api/types/request-command-result';
import { rememberEvents } from '../live/eventPositions';
import { encodedRouteParam } from '../routes/encodedRouteParam';
import type { CommentIntent } from './commentIntent';

/**
 * The action of a route below a review that shows comment boxes.
 */
export async function commentAction({ request, params }: ActionFunctionArgs): Promise<ActionResult> {
    const submitted = (await request.json()) as CommentIntent;

    return writeResult(() => writeComment(encodedRouteParam(params, 'reviewId'), submitted, request.signal));
}

export async function writeComment(reviewId: string, submitted: CommentIntent, signal: AbortSignal): Promise<unknown> {
    const review = `/api/v1/reviews/${reviewId}`;

    switch (submitted.intent) {
        case 'open':
            return apiWrite('POST', `${review}/requests`, submitted.input, signal);
        case 'reply':
            return rememberEvents((await apiWrite<RequestCommandResult>('POST', `/api/v1/requests/${encodeURIComponent(submitted.requestId)}/comments`, submitted.input, signal)).events);
        case 'draft':
            return apiWrite('POST', `${review}/drafts`, submitted.input, signal);
        case 'editDraft':
            return apiWrite('PATCH', `/api/v1/drafts/${encodeURIComponent(submitted.draftId)}`, submitted.input, signal);
        case 'deleteDraft':
            return apiWrite('DELETE', `/api/v1/drafts/${encodeURIComponent(submitted.draftId)}`, undefined, signal);
    }
}
