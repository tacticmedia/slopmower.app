import { Banner, Dialog, FormControl, Textarea } from '@primer/react';
import { type RefObject, useId, useRef } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import { ActionErrorMessage } from '../layout/ActionErrorMessage';
import { useSubmitOnce } from '../layout/useSubmitOnce';
import styles from './CloseReviewDialog.module.css';

type Props = {
    draftCount: number;
    returnFocusRef: RefObject<HTMLElement | null>;
    onClose(): void;
};

/**
 * Decision D27. The review actions menu renders it only while the review is open, so a successful close unmounts it.
 */
export function CloseReviewDialog({ draftCount, returnFocusRef, onClose }: Props) {
    const close = useFetcher<ActionResult>();
    const submitOnce = useSubmitOnce(close.submit);
    const note = useRef<HTMLTextAreaElement>(null);
    const formId = useId();
    const busy = 'idle' !== close.state;
    const closeUnlessBusy = (): void => {
        if (!busy) {
            onClose();
        }
    };

    return (
        <Dialog
            title="Close review"
            subtitle="A closed review is read-only, and it leaves the default lists."
            onClose={closeUnlessBusy}
            returnFocusRef={returnFocusRef}
            initialFocusRef={note}
            footerButtons={[
                { content: 'Cancel', onClick: closeUnlessBusy, disabled: busy },
                { content: 'Close review', buttonType: 'danger', type: 'submit', form: formId, loading: busy },
            ]}
        >
            <close.Form method="post" id={formId} className={styles.form} onSubmit={submitOnce}>
                <input type="hidden" name="intent" value="close" />
                {draftCount > 0 && (
                    <Banner
                        variant="warning"
                        title={1 === draftCount ? 'You have 1 pending comment' : `You have ${draftCount} pending comments`}
                        description="Closing the review deletes them."
                        data-testid="close-drafts-warning"
                    />
                )}
                <FormControl>
                    <FormControl.Label>Note</FormControl.Label>
                    <Textarea ref={note} name="note" block resize="vertical" />
                    <FormControl.Caption>Optional.</FormControl.Caption>
                </FormControl>
                <ActionErrorMessage result={close.data} />
            </close.Form>
        </Dialog>
    );
}
