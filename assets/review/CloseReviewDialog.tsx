import { Banner, Dialog, FormControl, Textarea } from '@primer/react';
import { type RefObject, useId, useRef } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import { ActionErrorMessage } from '../layout/ActionErrorMessage';
import { removeSessionDraft, useSessionDraft } from '../layout/useSessionDraft';
import { useSubmitOnce } from '../layout/useSubmitOnce';
import styles from './CloseReviewDialog.module.css';

type Props = {
    reviewId: string;
    draftCount: number;
    returnFocusRef: RefObject<HTMLElement | null>;
    onClose(): void;
};

/**
 * Decision D27. The review actions menu renders it only while the review is open, so a successful close unmounts it.
 * A reload keeps the note, and closing the dialog removes it (D109).
 */
export function CloseReviewDialog({ reviewId, draftCount, returnFocusRef, onClose }: Props) {
    const close = useFetcher<ActionResult>();
    const submitOnce = useSubmitOnce(close.submit);
    const note = useRef<HTMLTextAreaElement>(null);
    const formId = useId();
    const busy = 'idle' !== close.state;
    const draftKey = `review:${reviewId}:close-note`;
    const [noteText, setNoteText] = useSessionDraft(draftKey);
    const closeUnlessBusy = (): void => {
        if (!busy) {
            removeSessionDraft(draftKey);
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
                <input type="hidden" name="draftKey" value={draftKey} />
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
                    <Textarea ref={note} name="note" value={noteText} onChange={(event) => setNoteText(event.target.value)} block resize="vertical" />
                    <FormControl.Caption>Optional.</FormControl.Caption>
                </FormControl>
                <ActionErrorMessage result={close.data} />
            </close.Form>
        </Dialog>
    );
}
