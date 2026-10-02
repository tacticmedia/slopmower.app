import type { ActionFunctionArgs, LoaderFunctionArgs } from 'react-router';
import { type ActionResult, writeResult } from '../../api/actionResult';
import { apiGet, apiGetAllPages, apiGetText, apiWrite } from '../../api/client';
import type { FileContent } from '../../api/types/file-content';
import type { RequestPage } from '../../api/types/lists';
import type { Request as ReviewRequest } from '../../api/types/request';
import type { RequestCommandResult } from '../../api/types/request-command-result';
import type { CommentIntent } from '../../comments/commentIntent';
import { type ThreadView, threadView } from '../../comments/threadView';
import { rememberEvents } from '../../live/eventPositions';
import type { ViewedIntent } from '../../review/ViewedCheckbox';
import { encodedRouteParam } from '../encodedRouteParam';

const REQUEST_PAGE_LIMIT = 200;

export type FileView = {
    content: FileContent;
    /** Null when the file is not renderable. */
    text: string | null;
    threads: ThreadView[];
};

export async function fileLoader({ request, params }: LoaderFunctionArgs): Promise<FileView> {
    const reviewId = encodedRouteParam(params, 'reviewId');
    const content = await apiGet<FileContent>(`/api/v1/reviews/${reviewId}/file?path=${encodeURIComponent(params['*'] ?? '')}`, request.signal);
    const [text, threads] = await Promise.all([
        content.renderable ? apiGetText(content.rawUrl, request.signal) : null,
        fileThreads(reviewId, content.path, request.signal),
    ]);

    return { content, text, threads };
}

export async function fileAction({ request, params }: ActionFunctionArgs): Promise<ActionResult> {
    const submitted = (await request.json()) as CommentIntent | ViewedIntent;
    const review = `/api/v1/reviews/${encodedRouteParam(params, 'reviewId')}`;
    const { signal } = request;

    return writeResult(async () => {
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
            case 'viewed':
                return apiWrite('PUT', `${review}/viewed-files`, submitted.input, signal);
        }
    });
}

/**
 * Q1 matches a path prefix, and its summaries have no thread, so each request of the file is read with Q3.
 */
async function fileThreads(reviewId: string, path: string, signal: AbortSignal): Promise<ThreadView[]> {
    const query = new URLSearchParams({ path, sort: 'path', limit: String(REQUEST_PAGE_LIMIT) });
    const page = await apiGetAllPages<RequestPage>(`/api/v1/reviews/${reviewId}/requests?${query.toString()}`, signal);
    const requests = await Promise.all(
        page.items.filter((summary) => summary.anchor.path === path).map((summary) => apiGet<ReviewRequest>(`/api/v1/requests/${encodeURIComponent(summary.id)}`, signal)),
    );

    return requests.map(threadView);
}
