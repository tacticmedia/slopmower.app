import type { ActionFunctionArgs, LoaderFunctionArgs } from 'react-router';
import { type ActionResult, writeResult } from '../../api/actionResult';
import { apiGet, apiGetAllPages, apiGetText, apiWrite } from '../../api/client';
import type { Blame } from '../../api/types/blame';
import type { FileContent } from '../../api/types/file-content';
import type { RequestPage } from '../../api/types/lists';
import type { Request as ReviewRequest } from '../../api/types/request';
import { writeComment } from '../../comments/commentAction';
import type { CommentIntent } from '../../comments/commentIntent';
import { type ThreadView, threadView } from '../../comments/threadView';
import type { ViewedIntent } from '../../review/ViewedCheckbox';
import { encodedRouteParam } from '../encodedRouteParam';
import { REQUEST_PAGE_LIMIT } from '../requests/requestData';

export type FileView = {
    content: FileContent;
    /** Null when the file is not renderable. */
    text: string | null;
    threads: ThreadView[];
    /** Set on the blame route. */
    blame: Blame | null;
};

export async function fileLoader({ request, params }: LoaderFunctionArgs): Promise<FileView> {
    const reviewId = encodedRouteParam(params, 'reviewId');
    const content = await apiGet<FileContent>(`/api/v1/reviews/${reviewId}/file?path=${encodeURIComponent(params['*'] ?? '')}`, request.signal);
    const [text, threads] = await Promise.all([
        content.renderable ? apiGetText(content.rawUrl, request.signal) : null,
        fileThreads(reviewId, content.path, request.signal),
    ]);

    return { content, text, threads, blame: null };
}

/** The file route data plus V17. */
export async function blameLoader(args: LoaderFunctionArgs): Promise<FileView> {
    const blame = apiGet<Blame>(`/api/v1/reviews/${encodedRouteParam(args.params, 'reviewId')}/blame?path=${encodeURIComponent(args.params['*'] ?? '')}`, args.request.signal);
    const [view, loaded] = await Promise.all([fileLoader(args), blame]);

    return { ...view, blame: loaded };
}

export async function fileAction({ request, params }: ActionFunctionArgs): Promise<ActionResult> {
    const submitted = (await request.json()) as CommentIntent | ViewedIntent;
    const reviewId = encodedRouteParam(params, 'reviewId');
    const { signal } = request;

    return writeResult(() =>
        'viewed' === submitted.intent ? apiWrite('PUT', `/api/v1/reviews/${reviewId}/viewed-files`, submitted.input, signal) : writeComment(reviewId, submitted, signal),
    );
}

/**
 * Q1 matches a path prefix, and its summaries have no thread, so each request of the file is read with Q3.
 */
async function fileThreads(reviewId: string, path: string, signal: AbortSignal): Promise<ThreadView[]> {
    const query = new URLSearchParams({ path, sort: 'path', limit: String(REQUEST_PAGE_LIMIT) });
    const page = await apiGetAllPages<RequestPage>(`/api/v1/reviews/${reviewId}/requests?${query.toString()}`, signal);
    const requests = await Promise.all(
        page.items.filter((summary) => summary.anchor?.path === path).map((summary) => apiGet<ReviewRequest>(`/api/v1/requests/${encodeURIComponent(summary.id)}`, signal)),
    );

    return requests.map(threadView);
}
