import { type ActionFunctionArgs, type LoaderFunctionArgs, redirect, useLoaderData, useSearchParams } from 'react-router';
import { actionError, type ActionResult, optionalText, writeResult } from '../../api/actionResult';
import { apiGetAllPages, apiWrite } from '../../api/client';
import type { StartReviewInput } from '../../api/types/inputs';
import type { ReviewPage } from '../../api/types/lists';
import type { Review } from '../../api/types/review';
import { encodedRouteParam } from '../encodedRouteParam';
import { RepositoryHeader } from './RepositoryHeader';
import styles from './RepositoryRoute.module.css';
import { ReviewList } from './ReviewList';
import { StartReviewForm } from './StartReviewForm';

/** The shell query parameter of "Show closed", named after the `state` query of V2. */
const SHOW_CLOSED = { state: 'all' } as const;

export function repositoryLoader({ request, params }: LoaderFunctionArgs): Promise<ReviewPage> {
    const state = SHOW_CLOSED.state === new URL(request.url).searchParams.get('state') ? 'all' : 'open';

    return apiGetAllPages<ReviewPage>(`/api/v1/repositories/${encodedRouteParam(params, 'repositoryId')}/reviews?state=${state}&limit=100`, request.signal);
}

export async function repositoryAction({ request, params }: ActionFunctionArgs): Promise<ActionResult | Response> {
    const repositoryPath = `/api/v1/repositories/${encodedRouteParam(params, 'repositoryId')}`;
    const form = await request.formData();

    switch (form.get('intent')) {
        case 'refresh':
            return writeResult(() => apiWrite('POST', `${repositoryPath}/fetch`));
        case 'start':
            try {
                const input: StartReviewInput = { ref: optionalText(form.get('ref')), title: optionalText(form.get('title')) };
                const review = await apiWrite<Review>('POST', `${repositoryPath}/reviews`, input);

                return redirect(`/reviews/${review.id}`);
            } catch (error) {
                return actionError(error);
            }
        default:
            throw new Error('The form has no known intent.');
    }
}

export function RepositoryRoute() {
    const { repository, items } = useLoaderData<ReviewPage>();
    const [searchParams, setSearchParams] = useSearchParams();

    return (
        <div className={styles.page}>
            <RepositoryHeader repository={repository} />
            <StartReviewForm key={repository.defaultBranch} defaultBranch={repository.defaultBranch} />
            <ReviewList
                reviews={items}
                showClosed={SHOW_CLOSED.state === searchParams.get('state')}
                onShowClosedChange={(showClosed) => setSearchParams(showClosed ? SHOW_CLOSED : {})}
            />
        </div>
    );
}
