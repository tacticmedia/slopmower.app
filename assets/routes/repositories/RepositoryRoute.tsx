import { type ActionFunctionArgs, type LoaderFunctionArgs, redirect, useLoaderData, useSearchParams } from 'react-router';
import { actionError, type ActionResult, optionalText, writeResult } from '../../api/actionResult';
import { apiGetAllPages, apiWrite } from '../../api/client';
import type { CarryOverResult } from '../../api/types/carry-over-result';
import type { CarryOverInput, CheckEveryPullRequestInput, StartReviewInput } from '../../api/types/inputs';
import type { ReviewPage } from '../../api/types/lists';
import type { Review } from '../../api/types/review';
import { encodedRouteParam } from '../encodedRouteParam';
import { RepositoryHeader } from './RepositoryHeader';
import styles from './RepositoryRoute.module.css';
import { ReviewList } from './ReviewList';
import { type StartedWithCarryOver, StartReviewForm } from './StartReviewForm';

/** The shell query parameter of "Show closed", named after the `state` query of V2. */
const SHOW_CLOSED = { state: 'all' } as const;

export function repositoryLoader({ request, params }: LoaderFunctionArgs): Promise<ReviewPage> {
    const state = SHOW_CLOSED.state === new URL(request.url).searchParams.get('state') ? 'all' : 'open';

    return apiGetAllPages<ReviewPage>(`/api/v1/repositories/${encodedRouteParam(params, 'repositoryId')}/reviews?state=${state}&limit=100`, request.signal);
}

export async function repositoryAction({ request, params }: ActionFunctionArgs): Promise<ActionResult | StartedWithCarryOver | Response> {
    const repositoryPath = `/api/v1/repositories/${encodedRouteParam(params, 'repositoryId')}`;
    const form = await request.formData();

    switch (form.get('intent')) {
        case 'refresh':
            return writeResult(() => apiWrite('POST', `${repositoryPath}/fetch`));
        case 'check-every-pull-request': {
            const input: CheckEveryPullRequestInput = { enabled: 'true' === form.get('enabled') };

            return writeResult(() => apiWrite('POST', `${repositoryPath}/check-every-pull-request`, input));
        }
        case 'start':
            try {
                const input: StartReviewInput = { ref: optionalText(form.get('ref')), title: optionalText(form.get('title')) };
                const review = await apiWrite<Review>('POST', `${repositoryPath}/reviews`, input);
                const fromReviewId = optionalText(form.get('fromReviewId'));

                if (null === fromReviewId) {
                    return redirect(`/reviews/${review.id}`);
                }

                return await carryOver(review, fromReviewId);
            } catch (error) {
                return actionError(error);
            }
        default:
            throw new Error('The form has no known intent.');
    }
}

/** V15 after V1. The review exists already, so a failure says so. */
async function carryOver(review: Review, fromReviewId: string): Promise<ActionResult | StartedWithCarryOver> {
    try {
        const input: CarryOverInput = { fromReviewId };

        return { carryOver: await apiWrite<CarryOverResult>('POST', `/api/v1/reviews/${review.id}/carry-over`, input) };
    } catch (error) {
        return { error: `The review started, but the carry-over failed: ${actionError(error).error}` };
    }
}

export function RepositoryRoute() {
    const { repository, items } = useLoaderData<ReviewPage>();
    const [searchParams, setSearchParams] = useSearchParams();

    return (
        <div className={styles.page}>
            <RepositoryHeader repository={repository} />
            <StartReviewForm key={repository.defaultBranch} defaultBranch={repository.defaultBranch} openReviews={items.filter((review) => 'open' === review.state)} />
            <ReviewList
                reviews={items}
                showClosed={SHOW_CLOSED.state === searchParams.get('state')}
                onShowClosedChange={(showClosed) => setSearchParams(showClosed ? SHOW_CLOSED : {})}
            />
        </div>
    );
}
