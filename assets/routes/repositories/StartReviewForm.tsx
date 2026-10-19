import { Button, FormControl, Select, TextInput } from '@primer/react';
import type { ReactNode } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import type { CarryOverResult } from '../../api/types/carry-over-result';
import type { Review } from '../../api/types/review';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import { useSubmitOnce } from '../../layout/useSubmitOnce';
import { CarryOverPanel } from '../../review/CarryOverPanel';
import styles from './RepositoryRoute.module.css';

/** The action data of a start with carry-over. A start without it redirects to the review. */
export type StartedWithCarryOver = { carryOver: CarryOverResult };

type Props = {
    defaultBranch: string | null;
    /** The reviews that the new review can carry requests over from. */
    openReviews: Review[];
    /** Other ways to start, below the form. */
    children?: ReactNode;
};

export function StartReviewForm({ defaultBranch, openReviews, children }: Props) {
    const start = useFetcher<ActionResult | StartedWithCarryOver>();
    const submitOnce = useSubmitOnce(start.submit);
    const data = start.data ?? null;
    const started = null !== data && 'carryOver' in data ? data : null;
    const failed = null !== data && 'error' in data ? data : null;

    return (
        <section aria-labelledby="start-review">
            <h2 id="start-review" className={styles.sectionTitle}>
                Start a review
            </h2>
            <start.Form method="post" className={styles.startForm} data-testid="start-review-form" onSubmit={submitOnce}>
                <input type="hidden" name="intent" value="start" />
                <FormControl>
                    <FormControl.Label>Ref</FormControl.Label>
                    <TextInput name="ref" defaultValue={defaultBranch ?? ''} placeholder="Default branch" monospace block />
                    <FormControl.Caption>A branch, a tag, or a commit SHA.</FormControl.Caption>
                </FormControl>
                <FormControl>
                    <FormControl.Label>Title</FormControl.Label>
                    <TextInput name="title" placeholder="<ref> @ <commit>" block />
                    <FormControl.Caption>Optional.</FormControl.Caption>
                </FormControl>
                {0 < openReviews.length && (
                    <FormControl>
                        <FormControl.Label>Carry over open requests from</FormControl.Label>
                        <Select name="fromReviewId" block>
                            <Select.Option value="">No review</Select.Option>
                            {openReviews.map((review) => (
                                <Select.Option key={review.id} value={review.id}>
                                    {review.title}
                                </Select.Option>
                            ))}
                        </Select>
                        <FormControl.Caption>Copies its open requests to the new review, at its commit. The other review does not change.</FormControl.Caption>
                    </FormControl>
                )}
                <div className={styles.actions}>
                    <Button type="submit" variant="primary" loading={'idle' !== start.state}>
                        Start review
                    </Button>
                    <ActionErrorMessage result={failed} />
                </div>
            </start.Form>
            {null !== started && (
                <div className={styles.carryOverResult}>
                    <CarryOverPanel result={started.carryOver} linkToReview />
                </div>
            )}
            {children}
        </section>
    );
}
