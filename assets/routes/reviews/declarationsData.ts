import { useCallback, useEffect, useRef, useState } from 'react';
import { type LoaderFunctionArgs, useFetcher, useNavigate } from 'react-router';
import { actionError } from '../../api/actionResult';
import { apiGet } from '../../api/client';
import type { Declaration } from '../../api/types/declaration';
import type { DeclarationList } from '../../api/types/lists';
import type { NamePosition } from '../../code/CodeFile';
import { formatLineHash, lineRange } from '../../code/lineHash';
import { fileHref } from '../../review/fileHref';
import { encodedRouteParam } from '../encodedRouteParam';

/** The data of the `declarations` route that {@link useGoToDeclaration} loads. */
type DeclarationsResult = DeclarationList | { error: string };

/** A menu of targets, or a message. */
export type GoToOutcome = { targets: Declaration[] } | { message: string };

export const NO_DECLARATION = 'No declaration in this commit';

/** Forwards the query of the route: `path` with `line` and `column`, or with `name`. */
export async function declarationsLoader({ request, params }: LoaderFunctionArgs): Promise<DeclarationsResult> {
    try {
        return await apiGet<DeclarationList>(`/api/v1/reviews/${encodedRouteParam(params, 'reviewId')}/declarations${new URL(request.url).search}`, request.signal);
    } catch (error) {
        return actionError(error);
    }
}

/** A revalidation would deliver the last result again, and the hook would jump again. */
export function shouldRevalidateDeclarations(): boolean {
    return false;
}

/**
 * V14 for the open file. One target opens at once with its declaration line selected, several give a menu, none a
 * message. A jump pushes a history entry. A click first writes its line into the current entry, so Back returns to it.
 */
export function useGoToDeclaration(reviewId: string, path: string) {
    const { load, data } = useFetcher<DeclarationsResult>();
    const navigate = useNavigate();
    const lookedUp = useRef<{ path: string; clickedLine: number | null } | null>(null);
    const handled = useRef<DeclarationsResult | undefined>(undefined);
    const [outcome, setOutcome] = useState<{ path: string; value: GoToOutcome | null }>({ path, value: null });

    const lookUp = useCallback(
        (query: Record<string, string>, clickedLine: number | null) => {
            lookedUp.current = { path, clickedLine };
            setOutcome({ path, value: null });
            void load(`/reviews/${encodeURIComponent(reviewId)}/declarations?${new URLSearchParams({ path, ...query }).toString()}`);
        },
        [reviewId, path, load],
    );

    const go = useCallback(
        async (target: Declaration) => {
            setOutcome({ path, value: null });
            const clickedLine = lookedUp.current?.clickedLine ?? null;

            if (null !== clickedLine) {
                await navigate({ hash: formatLineHash(lineRange(clickedLine, clickedLine)) }, { replace: true });
            }
            await navigate(`${fileHref(reviewId, target.path)}${formatLineHash(lineRange(target.declarationStartLine, target.declarationStartLine))}`);
        },
        [reviewId, path, navigate],
    );

    useEffect(() => {
        if (undefined === data || data === handled.current) {
            return;
        }
        handled.current = data;

        // The reviewer opened another file while the lookup ran.
        if (lookedUp.current?.path !== path) {
            return;
        }

        if ('error' in data) {
            setOutcome({ path, value: { message: data.error } });

            return;
        }
        const [only, ...others] = data.items;

        if (undefined === only) {
            setOutcome({ path, value: { message: NO_DECLARATION } });
        } else if (0 === others.length) {
            void go(only);
        } else {
            setOutcome({ path, value: { targets: data.items } });
        }
    }, [data, path, go]);

    const atPosition = useCallback(({ line, column }: NamePosition) => lookUp({ line: String(line), column: String(column) }, line), [lookUp]);
    const named = useCallback((name: string) => lookUp({ name }, null), [lookUp]);
    const dismiss = useCallback(() => setOutcome({ path, value: null }), [path]);

    return { atPosition, named, go, dismiss, outcome: outcome.path === path ? outcome.value : null };
}
