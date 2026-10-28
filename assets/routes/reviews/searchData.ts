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

/** Keystrokes within this time load one search. */
const SEARCH_DELAY_MILLISECONDS = 150;

/**
 * V16 for a non-empty query, {@link SEARCH_DELAY_MILLISECONDS} after its last change. `result` is null until the
 * answer to this query arrives, so Enter never chooses a hit of an earlier query.
 */
export function useSearch(reviewId: string, query: string): { result: SearchResult | null; loading: boolean } {
    const { load, data } = useFetcher<SearchResult>();

    useEffect(() => {
        if ('' === query) {
            return;
        }
        const timer = setTimeout(() => void load(`/reviews/${encodeURIComponent(reviewId)}/search?${new URLSearchParams({ q: query }).toString()}`), SEARCH_DELAY_MILLISECONDS);

        return () => clearTimeout(timer);
    }, [reviewId, query, load]);
    const answered = '' !== query && data?.q === query;

    return { result: answered ? data : null, loading: '' !== query && !answered };
}
