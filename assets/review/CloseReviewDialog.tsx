import { Banner, Button, Dialog, FormControl, Textarea } from '@primer/react';
import { useId, useRef, useState } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import { ActionErrorMessage } from '../layout/ActionErrorMessage';
import styles from './CloseReviewDialog.module.css';

/**
 * Decision D27. The review layout renders it only while the review is open, so a successful close unmounts it.
 */
export function CloseReviewDialog({ draftCount }: { draftCount: number }) {
    const [open, setOpen] = useState(false);
    const close = useFetcher<ActionResult>();
    const openButton = useRef<HTMLButtonElement>(null);
    const note = useRef<HTMLTextAreaElement>(null);
    const formId = useId();

    return (
        <>
            <Button ref={openButton} onClick={() => setOpen(true)}>
                Close review
            </Button>
            {open && (
                <Dialog
                    title="Close review"
                    subtitle="A closed review is read-only, and it leaves the default lists."
                    onClose={() => setOpen(false)}
                    returnFocusRef={openButton}
                    initialFocusRef={note}
                    footerButtons={[
                        { content: 'Cancel', onClick: () => setOpen(false) },
                        { content: 'Close review', buttonType: 'danger', type: 'submit', form: formId, loading: 'idle' !== close.state },
                    ]}
                >
                    <close.Form method="post" id={formId} className={styles.form}>
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
            )}
        </>
    );
}
