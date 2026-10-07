import type { LoaderFunctionArgs } from 'react-router';
import { actionError } from '../../api/actionResult';
import { apiGetAllPages, apiGetText } from '../../api/client';
import type { TreeListing } from '../../api/types/lists';
import type { TreeEntry } from '../../api/types/tree-entry';
import { encodedRouteParam } from '../encodedRouteParam';

/** The data of the `tree-entries` route that the directory fetchers load. */
export type TreeResult = TreeListing | { error: string };

export type DirectoryView = {
    listing: TreeListing;
    readme: { entry: TreeEntry; markdown: string } | null;
};

const README = /^readme\.md$/i;

/** The direct entries of a directory, every page. `""` is the root. */
export function listDirectory(encodedReviewId: string, dir: string, signal: AbortSignal): Promise<TreeListing> {
    return apiGetAllPages<TreeListing>(`/api/v1/reviews/${encodedReviewId}/tree?dir=${encodeURIComponent(dir)}&limit=1000`, signal);
}

/** V8 of a path. */
export function rawHref(encodedReviewId: string, path: string): string {
    return `/api/v1/reviews/${encodedReviewId}/raw?path=${encodeURIComponent(path)}`;
}

export function treeHref(reviewId: string, dir: string): string {
    return `/reviews/${encodeURIComponent(reviewId)}/tree-entries?dir=${encodeURIComponent(dir)}`;
}

export async function treeLoader({ request, params }: LoaderFunctionArgs): Promise<TreeResult> {
    try {
        return await listDirectory(encodedRouteParam(params, 'reviewId'), new URL(request.url).searchParams.get('dir') ?? '', request.signal);
    } catch (error) {
        return actionError(error);
    }
}

/** The Code tab index shows the root, `tree/*` another directory. */
export async function directoryLoader({ request, params }: LoaderFunctionArgs): Promise<DirectoryView> {
    const reviewId = encodedRouteParam(params, 'reviewId');
    const listing = await listDirectory(reviewId, params['*'] ?? '', request.signal);
    const entry = listing.items.find(({ type, name }) => 'file' === type && README.test(name)) ?? null;

    return { listing, readme: null === entry ? null : { entry, markdown: await apiGetText(rawHref(reviewId, entry.path), request.signal) } };
}
