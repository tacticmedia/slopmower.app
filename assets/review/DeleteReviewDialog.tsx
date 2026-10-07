import { Button, Dialog } from '@primer/react';
import { useId, useRef, useState } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import type { Ulid } from '../api/types/common';
import { ActionErrorMessage } from '../layout/ActionErrorMessage';
import { useSubmitOnce } from '../layout/useSubmitOnce';
import styles from './DeleteReviewDialog.module.css';

/**
 * V13, for open and closed reviews. After the delete, the review action redirects to the repository page.
 */
export function DeleteReviewDialog({ repositoryId }: { repositoryId: Ulid }) {
    const [open, setOpen] = useState(false);
    const remove = useFetcher<ActionResult>();
    const submitOnce = useSubmitOnce(remove.submit);
    const openButton = useRef<HTMLButtonElement>(null);
    const formId = useId();
    const busy = 'idle' !== remove.state;
    const closeUnlessBusy = (): void => {
        if (!busy) {
            setOpen(false);
        }
    };

    return (
        <>
            <Button ref={openButton} variant="danger" onClick={() => setOpen(true)}>
                Delete review
            </Button>
            {open && (
                <Dialog
                    title="Delete review"
                    onClose={closeUnlessBusy}
                    returnFocusRef={openButton}
                    footerButtons={[
                        { content: 'Cancel', onClick: closeUnlessBusy, disabled: busy },
                        { content: 'Delete review', buttonType: 'danger', type: 'submit', form: formId, loading: busy },
                    ]}
                >
                    <remove.Form method="post" id={formId} className={styles.form} onSubmit={submitOnce}>
                        <input type="hidden" name="intent" value="delete" />
                        <input type="hidden" name="repositoryId" value={repositoryId} />
                        <p>The review is deleted with its requests, comments, linked commits, and the pending comments of every user. You cannot undo this.</p>
                        <ActionErrorMessage result={remove.data} />
                    </remove.Form>
                </Dialog>
            )}
        </>
    );
}
