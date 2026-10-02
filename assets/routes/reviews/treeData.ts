import type { LoaderFunctionArgs } from 'react-router';
import { actionError } from '../../api/actionResult';
import { apiGetAllPages } from '../../api/client';
import type { TreeListing } from '../../api/types/lists';
import { encodedRouteParam } from '../encodedRouteParam';

/** The data of the `tree` route that the directory fetchers load. */
export type TreeResult = TreeListing | { error: string };

/** The direct entries of a directory, every page. `""` is the root. */
export function listDirectory(encodedReviewId: string, dir: string, signal: AbortSignal): Promise<TreeListing> {
    return apiGetAllPages<TreeListing>(`/api/v1/reviews/${encodedReviewId}/tree?dir=${encodeURIComponent(dir)}&limit=1000`, signal);
}

export function treeHref(reviewId: string, dir: string): string {
    return `/reviews/${encodeURIComponent(reviewId)}/tree?dir=${encodeURIComponent(dir)}`;
}

export async function treeLoader({ request, params }: LoaderFunctionArgs): Promise<TreeResult> {
    try {
        return await listDirectory(encodedRouteParam(params, 'reviewId'), new URL(request.url).searchParams.get('dir') ?? '', request.signal);
    } catch (error) {
        return actionError(error);
    }
}
