import type { LoaderFunctionArgs } from 'react-router';
import { actionError } from '../../api/actionResult';
import { apiGetAllPages } from '../../api/client';
import type { ReviewPage } from '../../api/types/lists';
import { encodedRouteParam } from '../encodedRouteParam';

/** The data of the `carry-over-sources` route that the carry-over dialog loads. */
export type CarryOverSources = ReviewPage | { error: string };

export function carryOverSourcesHref(repositoryId: string): string {
    return `/repositories/${encodeURIComponent(repositoryId)}/carry-over-sources`;
}

/** V2 with the closed reviews, newest first. */
export async function carryOverSourcesLoader({ request, params }: LoaderFunctionArgs): Promise<CarryOverSources> {
    try {
        return await apiGetAllPages<ReviewPage>(`/api/v1/repositories/${encodedRouteParam(params, 'repositoryId')}/reviews?state=all&limit=100`, request.signal);
    } catch (error) {
        return actionError(error);
    }
}
