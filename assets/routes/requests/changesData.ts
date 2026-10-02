import { useEffect } from 'react';
import { type LoaderFunctionArgs, useFetcher } from 'react-router';
import { actionError } from '../../api/actionResult';
import { apiGet, apiGetText } from '../../api/client';
import type { CommitDiff } from '../../api/types/commit-diff';
import { encodedRouteParam } from '../encodedRouteParam';

type CommitDiffResult = CommitDiff | { error: string };

/** Null for the missing side of an added or a deleted file. */
export type DiffSides = { old: string | null; new: string | null };

type DiffSidesResult = DiffSides | { error: string };

/**
 * The loader of the `commits/:commitSha` route that {@link useCommitDiff} loads.
 */
export async function commitDiffLoader({ request, params }: LoaderFunctionArgs): Promise<CommitDiffResult> {
    try {
        return await apiGet<CommitDiff>(`/api/v1/requests/${encodedRouteParam(params, 'requestId')}/commits/${encodedRouteParam(params, 'commitSha')}`, request.signal);
    } catch (error) {
        return actionError(error);
    }
}

/**
 * The loader of the `diff-sides` route that {@link useDiffSides} loads: R7 for each side.
 */
export async function diffSidesLoader({ request, params }: LoaderFunctionArgs): Promise<DiffSidesResult> {
    const search = new URL(request.url).searchParams;
    const blob = (blobSha: string | null): Promise<string | null> =>
        null === blobSha ? Promise.resolve(null) : apiGetText(`/api/v1/repositories/${encodedRouteParam(params, 'repositoryId')}/blobs/${encodeURIComponent(blobSha)}`, request.signal);

    try {
        const [oldSide, newSide] = await Promise.all([blob(search.get('old')), blob(search.get('new'))]);

        return { old: oldSide, new: newSide };
    } catch (error) {
        return actionError(error);
    }
}

/** A commit diff of a link and its blobs never change. */
export function shouldRevalidateChanges(): boolean {
    return false;
}

/**
 * Q9. Undefined while it loads.
 */
export function useCommitDiff(requestId: string, commitSha: string): CommitDiffResult | undefined {
    const { load, data } = useFetcher<CommitDiffResult>();
    const href = `/requests/${encodeURIComponent(requestId)}/commits/${encodeURIComponent(commitSha)}`;

    useEffect(() => {
        void load(href);
    }, [href, load]);

    return data;
}

/**
 * Undefined while they load.
 */
export function useDiffSides(repositoryId: string, oldBlobSha: string | null, newBlobSha: string | null): DiffSidesResult | undefined {
    const { load, data } = useFetcher<DiffSidesResult>();
    const query = new URLSearchParams();
    if (null !== oldBlobSha) {
        query.set('old', oldBlobSha);
    }
    if (null !== newBlobSha) {
        query.set('new', newBlobSha);
    }
    const href = `/repositories/${encodeURIComponent(repositoryId)}/diff-sides?${query.toString()}`;

    useEffect(() => {
        void load(href);
    }, [href, load]);

    return data;
}
