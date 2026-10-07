import { useEffect } from 'react';
import { type LoaderFunctionArgs, useFetcher } from 'react-router';
import { actionError } from '../../api/actionResult';
import { apiGet } from '../../api/client';
import type { SymbolList } from '../../api/types/lists';
import type { Symbol as CodeSymbol } from '../../api/types/symbol';
import { encodedRouteParam } from '../encodedRouteParam';

/** The data of the `symbols` route that {@link useFileSymbols} loads. */
type SymbolsResult = SymbolList | { error: string; path: string };

/** `symbols` is null while they load, when they failed to load, and while the read is off. */
export type FileSymbols = { symbols: readonly CodeSymbol[] | null; failed: boolean };

export async function symbolsLoader({ request, params }: LoaderFunctionArgs): Promise<SymbolsResult> {
    const path = new URL(request.url).searchParams.get('path') ?? '';

    try {
        return await apiGet<SymbolList>(`/api/v1/reviews/${encodedRouteParam(params, 'reviewId')}/symbols?path=${encodeURIComponent(path)}`, request.signal);
    } catch (error) {
        return { ...actionError(error), path };
    }
}

/** The symbols of a blob at the review commit never change. */
export function shouldRevalidateSymbols(): boolean {
    return false;
}

/**
 * V9 of the file, while `enabled` is true.
 */
export function useFileSymbols(reviewId: string, path: string, enabled: boolean): FileSymbols {
    const { load, data } = useFetcher<SymbolsResult>();
    const href = enabled ? `/reviews/${encodeURIComponent(reviewId)}/symbols?path=${encodeURIComponent(path)}` : null;

    useEffect(() => {
        if (null !== href) {
            void load(href);
        }
    }, [href, load]);

    const current = null !== href && undefined !== data && data.path === path ? data : null;

    return { symbols: null !== current && 'items' in current ? current.items : null, failed: null !== current && 'error' in current };
}
