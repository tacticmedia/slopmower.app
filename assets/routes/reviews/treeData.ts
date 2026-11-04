import type { LoaderFunctionArgs } from 'react-router';
import { actionError } from '../../api/actionResult';
import { apiGet, apiGetText } from '../../api/client';
import type { TreeListing } from '../../api/types/lists';
import type { TreeEntry } from '../../api/types/tree-entry';
import { gitHubBlob, isCodeInBrowser, reviewRepositoryId } from '../../code/githubCode';
import { encodedRouteParam } from '../encodedRouteParam';

/** The data of the `tree-entries` route that the directory fetchers load. */
export type TreeResult = TreeListing | { error: string };

export type DirectoryView = {
    listing: TreeListing;
    /** `repositoryId` is set when the README came from GitHub, for Browser only (D114), and its images come from there too. */
    readme: { entry: TreeEntry; markdown: string; repositoryId: string | null } | null;
};

const README = /^readme\.md$/i;

const PAGE_ENTRIES = 1000;

/** The table and the tree show one page, as GitHub does, so a directory of many files loads and renders fast. */
export const TRUNCATED_TEXT = `Showing the first ${new Intl.NumberFormat('en-US').format(PAGE_ENTRIES)} entries.`;

/** The first page of the direct entries of a directory. `""` is the root. `nextCursor` is set when it has more. */
export function listDirectory(encodedReviewId: string, dir: string, signal: AbortSignal): Promise<TreeListing> {
    return apiGet<TreeListing>(`/api/v1/reviews/${encodedReviewId}/tree?dir=${encodeURIComponent(dir)}&limit=${PAGE_ENTRIES}`, signal);
}

/** V8 of a path. */
export function rawHref(encodedReviewId: string, path: string): string {
    return `/api/v1/reviews/${encodedReviewId}/raw?path=${encodeURIComponent(path)}`;
}

export function treeHref(reviewId: string, dir: string): string {
    return `/reviews/${encodeURIComponent(reviewId)}/tree-entries?dir=${encodeURIComponent(dir)}`;
}

/** The tree of a review commit never changes. */
export function shouldRevalidateTree(): boolean {
    return false;
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

    return { listing, readme: null === entry ? null : await readme(listing.reviewId, entry, request.signal) };
}

/** V8, or the blob from GitHub when V8 answers `code_in_browser`. */
async function readme(reviewId: string, entry: TreeEntry, signal: AbortSignal): Promise<DirectoryView['readme']> {
    try {
        return { entry, markdown: await apiGetText(rawHref(encodeURIComponent(reviewId), entry.path), signal), repositoryId: null };
    } catch (error) {
        if (!isCodeInBrowser(error)) {
            throw error;
        }
        const repositoryId = await reviewRepositoryId(reviewId);

        return { entry, markdown: new TextDecoder().decode(await gitHubBlob(repositoryId, entry.sha, signal)), repositoryId };
    }
}
