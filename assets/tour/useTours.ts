import { useCallback, useEffect, useMemo } from 'react';
import { type ActionFunctionArgs, type LoaderFunctionArgs, type ShouldRevalidateFunctionArgs, useFetcher, useRouteLoaderData } from 'react-router';
import { type ActionResult, writeResult } from '../api/actionResult';
import { apiGet, apiWrite } from '../api/client';
import { type KnownTour, TOURS, type Tour } from '../api/types/common';
import type { PreferencesInput } from '../api/types/inputs';
import type { TokenList } from '../api/types/lists';
import type { Me } from '../api/types/me';

/** Action-only and loader-only root routes. Nothing navigates to them, so the server has no shell route for them. */
export const PREFERENCES_PATH = '/preferences';
export const TOUR_TOKENS_PATH = '/tour-tokens';

/** A4 with the body of the fetcher. */
export async function preferencesAction({ request }: ActionFunctionArgs): Promise<ActionResult> {
    const input = (await request.json()) as PreferencesInput;

    return writeResult(() => apiWrite('PATCH', '/api/v1/me/preferences', input, request.signal));
}

/** For the root route: after A4, only `me` loads again. */
export function shouldRevalidateRoot({ formAction, defaultShouldRevalidate }: ShouldRevalidateFunctionArgs): boolean {
    return PREFERENCES_PATH === formAction || defaultShouldRevalidate;
}

/** Whether T1 lists an active token. Null when T1 fails. */
export async function tourTokensLoader({ request }: LoaderFunctionArgs): Promise<boolean | null> {
    try {
        const { items } = await apiGet<TokenList>('/api/v1/tokens', request.signal);

        return items.some(({ state }) => 'active' === state);
    } catch {
        return null;
    }
}

export type Tours = {
    /** In the order of `TOURS`. While a write runs, the list that it sends. */
    completed: readonly KnownTour[];
    saving: boolean;
    result: ActionResult | undefined;
    save(completed: readonly KnownTour[]): void;
};

/**
 * The completed tours of `me`, and their write through the root `preferences` action.
 */
export function useTours(): Tours {
    const me = useRouteLoaderData<Me>('root');
    if (undefined === me) {
        throw new Error('The tours render inside the root route.');
    }
    const { submit, state, json, data } = useFetcher<ActionResult>();
    const saving = 'idle' !== state;
    const sent = saving ? (json as PreferencesInput | undefined)?.completedTours : null;
    const stored = me.preferences.completedTours;
    const completed = useMemo(() => known(sent ?? stored), [sent, stored]);
    const save = useCallback(
        (tours: readonly KnownTour[]) => {
            const input: PreferencesInput = { completedTours: known(tours) };
            void submit(input, { method: 'post', action: PREFERENCES_PATH, encType: 'application/json', defaultShouldRevalidate: false });
        },
        [submit],
    );

    return { completed, saving, result: data, save };
}

/** T1 while `enabled`: undefined while it loads, null when it failed. */
export function useActiveToken(enabled: boolean): boolean | null | undefined {
    const { load, data } = useFetcher<boolean | null>();

    useEffect(() => {
        if (enabled) {
            void load(TOUR_TOKENS_PATH);
        }
    }, [enabled, load]);

    return data;
}

function known(tours: readonly Tour[]): KnownTour[] {
    return TOURS.filter((tour) => tours.includes(tour));
}
