import { Dialog } from '@primer/react';
import { type RefObject, useId } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import type { Ulid } from '../api/types/common';
import { ActionErrorMessage } from '../layout/ActionErrorMessage';
import { useSubmitOnce } from '../layout/useSubmitOnce';
import styles from './DeleteReviewDialog.module.css';

type Props = {
    repositoryId: Ulid;
    returnFocusRef: RefObject<HTMLElement | null>;
    onClose(): void;
};

/**
 * V13, for open and closed reviews. After the delete, the review action redirects to the repository page.
 */
export function DeleteReviewDialog({ repositoryId, returnFocusRef, onClose }: Props) {
    const remove = useFetcher<ActionResult>();
    const submitOnce = useSubmitOnce(remove.submit);
    const formId = useId();
    const busy = 'idle' !== remove.state;
    const closeUnlessBusy = (): void => {
        if (!busy) {
            onClose();
        }
    };

    return (
        <Dialog
            title="Delete review"
            onClose={closeUnlessBusy}
            returnFocusRef={returnFocusRef}
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
    );
}
