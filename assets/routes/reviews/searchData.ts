import { useEffect } from 'react';
import { type LoaderFunctionArgs, useFetcher } from 'react-router';
import { actionError } from '../../api/actionResult';
import { apiGet } from '../../api/client';
import type { SearchHitList } from '../../api/types/lists';
import { encodedRouteParam } from '../encodedRouteParam';

/** The data of the `search` route that {@link useSearch} loads, with the query that it answers. */
export type SearchResult = { q: string } & (SearchHitList | { error: string });

export async function searchLoader({ request, params }: LoaderFunctionArgs): Promise<SearchResult> {
    const q = new URL(request.url).searchParams.get('q') ?? '';

    try {
        return { q, ...(await apiGet<SearchHitList>(`/api/v1/reviews/${encodedRouteParam(params, 'reviewId')}/search?${new URLSearchParams({ q }).toString()}`, request.signal)) };
    } catch (error) {
        return { q, ...actionError(error) };
    }
}

/** A revalidation would replace the hits while the reviewer moves through them. */
export function shouldRevalidateSearch(): boolean {
    return false;
}

/**
 * V16 for a non-empty query. `result` is null until the answer to this query arrives, so Enter never chooses a hit of
 * an earlier query.
 */
export function useSearch(reviewId: string, query: string): { result: SearchResult | null; loading: boolean } {
    const { load, data, state } = useFetcher<SearchResult>();

    useEffect(() => {
        if ('' !== query) {
            void load(`/reviews/${encodeURIComponent(reviewId)}/search?${new URLSearchParams({ q: query }).toString()}`);
        }
    }, [reviewId, query, load]);

    return { result: '' !== query && data?.q === query ? data : null, loading: 'idle' !== state };
}
