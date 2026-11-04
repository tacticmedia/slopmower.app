import { useEffect, useState } from 'react';
import { type LoaderFunctionArgs, useFetcher } from 'react-router';
import { actionError } from '../../api/actionResult';
import { apiGet } from '../../api/client';
import type { SymbolList } from '../../api/types/lists';
import type { Symbol as CodeSymbol } from '../../api/types/symbol';
import type { OutlineAnswer, OutlineRequest } from '../../code/outlineWorker';
import { encodedRouteParam } from '../encodedRouteParam';

/** The data of the `symbols` route that {@link useFileSymbols} loads. */
type SymbolsResult = SymbolList | { error: string; path: string };

/** `symbols` is null while they load, when they failed to load, and while the read is off. */
export type FileSymbols = { symbols: readonly CodeSymbol[] | null; failed: boolean };

/** The text of a renderable file. `inBrowser` parses it in a worker, for Browser only (D114). */
export type SymbolSource = { language: string | null; text: string; inBrowser: boolean };

/** One worker serves the page, and keeps its grammars. */
let outlineWorker: Worker | null = null;

let lastOutlineId = 0;

const outlineAnswers = new Map<number, (symbols: CodeSymbol[] | null) => void>();

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
 * V9 of the file, or the outline of the worker, while `source` is set.
 */
export function useFileSymbols(reviewId: string, path: string, source: SymbolSource | null): FileSymbols {
    const { load, data } = useFetcher<SymbolsResult>();
    const href = null !== source && !source.inBrowser ? `/reviews/${encodeURIComponent(reviewId)}/symbols?path=${encodeURIComponent(path)}` : null;
    const outlined = null !== source && source.inBrowser ? source : null;
    const [outline, setOutline] = useState<{ text: string; symbols: CodeSymbol[] | null } | null>(null);

    useEffect(() => {
        if (null !== href) {
            void load(href);
        }
    }, [href, load]);

    const outlineLanguage = outlined?.language ?? null;
    const outlineText = outlined?.text ?? null;
    useEffect(() => {
        if (null === outlineLanguage || null === outlineText) {
            return;
        }
        let current = true;
        void outlineOf(outlineLanguage, outlineText).then((symbols) => current && setOutline({ text: outlineText, symbols }));

        return () => {
            current = false;
        };
    }, [outlineLanguage, outlineText]);

    if (null !== outlined) {
        const parsed = outline?.text === outlined.text ? outline : null;

        return { symbols: null === outlined.language ? [] : (parsed?.symbols ?? null), failed: null !== parsed && null === parsed.symbols };
    }

    const current = null !== href && undefined !== data && data.path === path ? data : null;

    return { symbols: null !== current && 'items' in current ? current.items : null, failed: null !== current && 'error' in current };
}

function outlineOf(language: string, text: string): Promise<CodeSymbol[] | null> {
    if (null === outlineWorker) {
        outlineWorker = new Worker(new URL('../../code/outlineWorker.ts', import.meta.url), { type: 'module' });
        outlineWorker.onmessage = ({ data: { id, symbols } }: MessageEvent<OutlineAnswer>) => {
            outlineAnswers.get(id)?.(symbols);
            outlineAnswers.delete(id);
        };
        // A worker that fails to load answers nothing: the pending outlines fail, and the next one starts a new worker.
        outlineWorker.onerror = () => {
            for (const answer of outlineAnswers.values()) {
                answer(null);
            }
            outlineAnswers.clear();
            outlineWorker = null;
        };
    }
    const id = ++lastOutlineId;
    const worker = outlineWorker;

    return new Promise((resolve) => {
        outlineAnswers.set(id, resolve);
        worker.postMessage({ id, language, text } satisfies OutlineRequest);
    });
}
