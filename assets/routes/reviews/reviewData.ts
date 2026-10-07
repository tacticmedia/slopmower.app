import { type ActionFunctionArgs, type LoaderFunctionArgs, redirect } from 'react-router';
import { actionError, type ActionResult, optionalText, writeResult } from '../../api/actionResult';
import { apiGet, apiWrite } from '../../api/client';
import type { BatchSubmission } from '../../api/types/batch-submission';
import type { CarryOverResult } from '../../api/types/carry-over-result';
import type { Draft } from '../../api/types/draft';
import type { CarryOverInput, CloseReviewInput, SubmitDraftsInput } from '../../api/types/inputs';
import type { DraftList, ViewedFiles } from '../../api/types/lists';
import type { Review } from '../../api/types/review';
import { eventPositions } from '../../live/eventPositions';
import { encodedRouteParam } from '../encodedRouteParam';

/** The data of the `review` route. Drafts and viewed files belong to the caller. */
export type ReviewData = {
    review: Review;
    drafts: Draft[];
    viewedPaths: string[];
};

export async function reviewLoader({ request, params }: LoaderFunctionArgs): Promise<ReviewData> {
    const review = `/api/v1/reviews/${encodedRouteParam(params, 'reviewId')}`;
    const [loaded, drafts, viewed] = await Promise.all([
        apiGet<Review>(review, request.signal),
        apiGet<DraftList>(`${review}/drafts`, request.signal),
        apiGet<ViewedFiles>(`${review}/viewed-files`, request.signal),
    ]);

    return { review: loaded, drafts: drafts.items, viewedPaths: viewed.paths };
}

/**
 * Form field `intent`: `close` for V4, `submit` for D5, `delete` for V13 with the form field `repositoryId`,
 * `carry_over` for V15 with the form field `fromReviewId`.
 */
export async function reviewAction({ request, params }: ActionFunctionArgs): Promise<ActionResult | CarryOverResult | Response> {
    const form = await request.formData();
    const review = `/api/v1/reviews/${encodedRouteParam(params, 'reviewId')}`;
    const intent = form.get('intent');

    if ('carry_over' === intent) {
        const input: CarryOverInput = { fromReviewId: String(form.get('fromReviewId')) };

        try {
            return await apiWrite<CarryOverResult>('POST', `${review}/carry-over`, input);
        } catch (error) {
            return actionError(error);
        }
    }

    if ('delete' === intent) {
        try {
            await apiWrite('DELETE', review);
        } catch (error) {
            return actionError(error);
        }

        return redirect(`/repositories/${encodeURIComponent(String(form.get('repositoryId')))}`);
    }

    if ('submit' === intent) {
        const input: SubmitDraftsInput = { summary: optionalText(form.get('summary')) };

        return writeResult(async () => {
            const submission = await apiWrite<BatchSubmission>('POST', `${review}/drafts/submit`, input);
            // The router revalidates after this action, so the live update of the batch needs no revalidation.
            eventPositions.written(submission.reviewId, submission.seq);
        });
    }

    if ('close' !== intent) {
        throw new Error(`The review route has no intent ${String(intent)}.`);
    }

    const input: CloseReviewInput = { note: optionalText(form.get('note')) };

    return writeResult(() => apiWrite('POST', `${review}/close`, input));
}
