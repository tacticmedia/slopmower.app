import { Dialog, type DialogButtonProps, FormControl, Select, Spinner } from '@primer/react';
import { type RefObject, useEffect, useId } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import type { CarryOverResult } from '../api/types/carry-over-result';
import type { Review } from '../api/types/review';
import { ActionErrorMessage } from '../layout/ActionErrorMessage';
import { useSubmitOnce } from '../layout/useSubmitOnce';
import { type CarryOverSources, carryOverSourcesHref } from '../routes/reviews/carryOverData';
import styles from './CarryOverDialog.module.css';
import { CarryOverPanel } from './CarryOverPanel';

const TITLE = 'Carry over requests';

type Props = {
    review: Review;
    returnFocusRef: RefObject<HTMLElement | null>;
    onClose(): void;
};

/**
 * V15 into this review. The fetchers live only while the dialog is open, so each opening loads the reviews again and
 * starts with the form.
 */
export function CarryOverDialog({ review, returnFocusRef, onClose }: Props) {
    const sources = useFetcher<CarryOverSources>();
    const carry = useFetcher<ActionResult | CarryOverResult>();
    const submitOnce = useSubmitOnce(carry.submit);
    const formId = useId();
    const busy = 'idle' !== carry.state;
    const closeUnlessBusy = (): void => {
        if (!busy) {
            onClose();
        }
    };
    const { load } = sources;
    const href = carryOverSourcesHref(review.repositoryId);

    useEffect(() => {
        void load(href);
    }, [href, load]);

    const result = carry.data ?? null;
    if (null !== result && 'items' in result) {
        return (
            <Dialog title={TITLE} onClose={onClose} returnFocusRef={returnFocusRef} footerButtons={[{ content: 'Done', buttonType: 'primary', onClick: onClose }]}>
                <CarryOverPanel result={result} linkToReview={false} />
            </Dialog>
        );
    }

    const loaded = sources.data;
    const others = undefined === loaded || 'error' in loaded ? [] : loaded.items.filter(({ id }) => id !== review.id);
    const submit: DialogButtonProps[] = 0 < others.length ? [{ content: 'Carry over', buttonType: 'primary', type: 'submit', form: formId, loading: busy }] : [];

    return (
        <Dialog title={TITLE} onClose={closeUnlessBusy} returnFocusRef={returnFocusRef} footerButtons={[{ content: 'Cancel', onClick: closeUnlessBusy, disabled: busy }, ...submit]}>
            {0 < others.length ? (
                <carry.Form method="post" id={formId} className={styles.form} onSubmit={submitOnce}>
                    <input type="hidden" name="intent" value="carry_over" />
                    <FormControl>
                        <FormControl.Label>Carry over open requests from</FormControl.Label>
                        <Select name="fromReviewId" block>
                            {others.map((source) => (
                                <Select.Option key={source.id} value={source.id}>
                                    {'closed' === source.state ? `${source.title} (closed)` : source.title}
                                </Select.Option>
                            ))}
                        </Select>
                        <FormControl.Caption>Copies its open requests to this review, at the commit of this review. The other review does not change.</FormControl.Caption>
                    </FormControl>
                    <ActionErrorMessage result={result} />
                </carry.Form>
            ) : (
                <SourcesNotice sources={loaded} />
            )}
        </Dialog>
    );
}

/** Shown in place of the form while there is no review to choose. */
function SourcesNotice({ sources }: { sources: CarryOverSources | undefined }) {
    if (undefined === sources) {
        return (
            <p className={styles.notice}>
                <Spinner size="small" srText={null} /> Loading the reviews
            </p>
        );
    }
    if ('error' in sources) {
        return <ActionErrorMessage result={sources} />;
    }

    return <p className={styles.notice}>No other review of this repository</p>;
}
