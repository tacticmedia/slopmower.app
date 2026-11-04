import { useEffect } from 'react';
import { type LoaderFunctionArgs, useFetcher } from 'react-router';
import { actionError } from '../../api/actionResult';
import { apiGet, apiGetText } from '../../api/client';
import type { CommitDiff } from '../../api/types/commit-diff';
import { gitHubBlob, isBinary } from '../../code/githubCode';
import { encodedRouteParam } from '../encodedRouteParam';

type CommitDiffResult = CommitDiff | { error: string };

/** Null for the missing side of an added or a deleted file. `binary` comes from the blobs of Browser only (D114). */
export type DiffSides = { old: string | null; new: string | null; binary: boolean };

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
 * The loader of the `diff-sides` route that {@link useDiffSides} loads: R7 for each side, or with `github` the blobs from
 * GitHub, whose binary check runs here.
 */
export async function diffSidesLoader({ request, params }: LoaderFunctionArgs): Promise<DiffSidesResult> {
    const search = new URL(request.url).searchParams;
    const repositoryId = encodedRouteParam(params, 'repositoryId');
    const fromGitHub = search.has('github');
    const blob = async (blobSha: string | null): Promise<Uint8Array | string | null> => {
        if (null === blobSha) {
            return null;
        }

        return fromGitHub ? gitHubBlob(decodeURIComponent(repositoryId), blobSha, request.signal) : apiGetText(`/api/v1/repositories/${repositoryId}/blobs/${encodeURIComponent(blobSha)}`, request.signal);
    };

    try {
        const sides = await Promise.all([blob(search.get('old')), blob(search.get('new'))]);
        const binary = sides.some((side) => side instanceof Uint8Array && isBinary(side));
        const [oldSide, newSide] = sides.map((side) => (side instanceof Uint8Array ? new TextDecoder().decode(side) : side));

        return { old: oldSide ?? null, new: newSide ?? null, binary };
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
 * Undefined while they load. `fromGitHub` reads the blobs of Browser only from GitHub.
 */
export function useDiffSides(repositoryId: string, oldBlobSha: string | null, newBlobSha: string | null, fromGitHub: boolean): DiffSidesResult | undefined {
    const { load, data } = useFetcher<DiffSidesResult>();
    const query = new URLSearchParams();
    if (null !== oldBlobSha) {
        query.set('old', oldBlobSha);
    }
    if (null !== newBlobSha) {
        query.set('new', newBlobSha);
    }
    if (fromGitHub) {
        query.set('github', '1');
    }
    const href = `/repositories/${encodeURIComponent(repositoryId)}/diff-sides?${query.toString()}`;

    useEffect(() => {
        void load(href);
    }, [href, load]);

    return data;
}
