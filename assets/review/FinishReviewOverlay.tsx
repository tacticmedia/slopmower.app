import { AnchoredOverlay, Button, FormControl, Textarea } from '@primer/react';
import { useRef, useState } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import { ActionErrorMessage } from '../layout/ActionErrorMessage';
import { useSubmitOnce } from '../layout/useSubmitOnce';
import styles from './FinishReviewOverlay.module.css';

/**
 * D5 with an optional summary. The review layout renders it only while drafts exist, so a successful submission
 * unmounts it.
 */
export function FinishReviewOverlay({ draftCount }: { draftCount: number }) {
    const [open, setOpen] = useState(false);
    const submission = useFetcher<ActionResult>();
    const submitOnce = useSubmitOnce(submission.submit);
    const summary = useRef<HTMLTextAreaElement>(null);
    const busy = 'idle' !== submission.state;

    return (
        <AnchoredOverlay
            open={open}
            onOpen={() => setOpen(true)}
            onClose={() => setOpen(busy)}
            renderAnchor={(anchorProps) => (
                <Button {...anchorProps} variant="primary" count={draftCount} data-testid="finish-review">
                    Finish your review
                </Button>
            )}
            focusTrapSettings={{ initialFocusRef: summary }}
            // The default focus zone is for menus: it takes the fields out of the Tab order.
            focusZoneSettings={{ disabled: true }}
            overlayProps={{ role: 'dialog', 'aria-label': 'Finish your review' }}
        >
            <submission.Form method="post" className={styles.form} data-testid="finish-review-form" onSubmit={submitOnce}>
                <input type="hidden" name="intent" value="submit" />
                <FormControl>
                    <FormControl.Label>Summary</FormControl.Label>
                    <Textarea ref={summary} name="summary" block resize="vertical" rows={4} placeholder="Leave a comment" readOnly={busy} />
                    <FormControl.Caption>Optional.</FormControl.Caption>
                </FormControl>
                <ActionErrorMessage result={submission.data} />
                <div className={styles.actions}>
                    <Button onClick={() => setOpen(false)} disabled={busy}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" loading={busy}>
                        Submit review
                    </Button>
                </div>
            </submission.Form>
        </AnchoredOverlay>
    );
}
