import { Link } from '@primer/react';
import { type ActionFunctionArgs, type LoaderFunctionArgs, redirect, Link as RouterLink, useLoaderData, useSearchParams } from 'react-router';
import { actionError, type ActionResult, optionalText, writeResult } from '../../api/actionResult';
import { ApiError, apiGet, apiGetAllPages, apiWrite } from '../../api/client';
import type { ApiToken } from '../../api/types/api-token';
import type { CarryOverResult } from '../../api/types/carry-over-result';
import type { CarryOverInput, CheckEveryPullRequestInput, StartReviewInput } from '../../api/types/inputs';
import type { CommitLog, ReviewPage, TokenList } from '../../api/types/lists';
import type { Review } from '../../api/types/review';
import { OnboardingSteps } from '../../onboarding/OnboardingSteps';
import { encodedRouteParam } from '../encodedRouteParam';
import { LatestCommits, type LatestCommitsData } from './LatestCommits';
import { RepositoryHeader } from './RepositoryHeader';
import styles from './RepositoryRoute.module.css';
import { ReviewList } from './ReviewList';
import { type StartedWithCarryOver, StartReviewForm } from './StartReviewForm';

/** The shell query parameter of "Show closed", named after the `state` query of V2. */
const SHOW_CLOSED = { state: 'all' } as const;

export type RepositoryData = {
    page: ReviewPage;
    /** Null while the repository has no default branch. */
    commits: LatestCommitsData | null;
    /** T1, read only while the repository has no open review: the getting started steps show then. */
    tokens: ApiToken[] | null;
};

/** R9 and T1 follow V2, which gives the default branch and the open reviews. */
export async function repositoryLoader({ request, params }: LoaderFunctionArgs): Promise<RepositoryData> {
    const repositoryPath = `/api/v1/repositories/${encodedRouteParam(params, 'repositoryId')}`;
    const state = SHOW_CLOSED.state === new URL(request.url).searchParams.get('state') ? 'all' : 'open';
    const page = await apiGetAllPages<ReviewPage>(`${repositoryPath}/reviews?state=${state}&limit=100`, request.signal);
    const branch = page.repository.defaultBranch;
    const [commits, tokens] = await Promise.all([
        null === branch ? null : latestCommits(repositoryPath, branch, request.signal),
        page.items.some((review) => 'open' === review.state) ? null : apiGet<TokenList>('/api/v1/tokens', request.signal).then(({ items }) => items),
    ]);

    return { page, commits, tokens };
}

/** A clone that is not ready, 409, or a branch that the clone lacks, 422, keeps the page. */
async function latestCommits(repositoryPath: string, branch: string, signal: AbortSignal): Promise<LatestCommitsData> {
    try {
        return { branch, log: await apiGet<CommitLog>(`${repositoryPath}/commits`, signal), error: null };
    } catch (error) {
        if (error instanceof ApiError && (409 === error.status || 422 === error.status)) {
            return { branch, log: null, error };
        }

        throw error;
    }
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

/** The open reviews come first when there are any. Without one, the getting started steps follow "Start a review". */
export function RepositoryRoute() {
    const {
        page: { repository, items },
        commits,
        tokens,
    } = useLoaderData<RepositoryData>();
    const [searchParams, setSearchParams] = useSearchParams();
    const showClosed = SHOW_CLOSED.state === searchParams.get('state');
    const openReviews = items.filter((review) => 'open' === review.state);
    const reviewList = <ReviewList reviews={items} showClosed={showClosed} onShowClosedChange={(show) => setSearchParams(show ? SHOW_CLOSED : {})} />;
    const startReview = (
        <StartReviewForm key={repository.defaultBranch} defaultBranch={repository.defaultBranch} openReviews={openReviews}>
            {null !== commits && <LatestCommits commits={commits} lastFetchedAt={repository.sync.lastFetchedAt} />}
        </StartReviewForm>
    );

    if (null === tokens) {
        return (
            <div className={styles.page}>
                <RepositoryHeader repository={repository} />
                {reviewList}
                {startReview}
            </div>
        );
    }

    return (
        <div className={styles.page}>
            <RepositoryHeader repository={repository} />
            <div className={styles.gettingStarted}>
                {startReview}
                <OnboardingSteps form="compact" tokens={tokens} />
            </div>
            {0 < items.length || showClosed ? (
                reviewList
            ) : (
                <p className={styles.meta}>
                    <Link as={RouterLink} to={`?state=${SHOW_CLOSED.state}`}>
                        Show closed reviews
                    </Link>
                </p>
            )}
        </div>
    );
}
