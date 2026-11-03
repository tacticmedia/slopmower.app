import { AnchoredOverlay, Button, FormControl, Select, Textarea } from '@primer/react';
import { useRef, useState } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import type { Delivery } from '../api/types/common';
import { ActionErrorMessage } from '../layout/ActionErrorMessage';
import { removeSessionDraft, useSessionDraft } from '../layout/useSessionDraft';
import { useSubmitOnce } from '../layout/useSubmitOnce';
import styles from './SubmitFeedbackOverlay.module.css';

const WORKSPACE_OPTIONS = [
    ['', 'Agent decides'],
    ['current_branch', 'Current branch'],
    ['new_branch', 'New branch'],
    ['worktree', 'Worktree'],
] as const;

const PULL_REQUEST_OPTIONS = [
    ['', 'Agent decides'],
    ['true', 'Open one'],
    ['false', 'None'],
] as const;

type Props = {
    reviewId: string;
    draftCount: number;
    /** The stored choice, which the selects start from. */
    delivery: Delivery;
    /** The host has pull requests, so the pull request choice shows. */
    pullRequests: boolean;
};

/**
 * D5 with an optional summary and the delivery choice (D85). The review layout renders it only while drafts exist, so
 * a successful submission unmounts it. A reload keeps the summary, and closing without a submission removes it (D109).
 */
export function SubmitFeedbackOverlay({ reviewId, draftCount, delivery, pullRequests }: Props) {
    const [open, setOpen] = useState(false);
    const submission = useFetcher<ActionResult>();
    const submitOnce = useSubmitOnce(submission.submit);
    const summary = useRef<HTMLTextAreaElement>(null);
    const busy = 'idle' !== submission.state;
    const draftKey = `review:${reviewId}:feedback-summary`;
    const [summaryText, setSummaryText, revertSummary] = useSessionDraft(draftKey);
    const close = (): void => {
        if (!busy) {
            removeSessionDraft(draftKey);
            revertSummary();
            setOpen(false);
        }
    };

    return (
        <AnchoredOverlay
            open={open}
            onOpen={() => setOpen(true)}
            onClose={close}
            renderAnchor={(anchorProps) => (
                <Button {...anchorProps} variant="primary" count={draftCount} data-testid="submit-feedback">
                    Submit feedback
                </Button>
            )}
            focusTrapSettings={{ initialFocusRef: summary }}
            // The default focus zone is for menus: it takes the fields out of the Tab order.
            focusZoneSettings={{ disabled: true }}
            overlayProps={{ role: 'dialog', 'aria-label': 'Submit feedback' }}
        >
            <submission.Form method="post" className={styles.form} data-testid="submit-feedback-form" onSubmit={submitOnce}>
                <input type="hidden" name="intent" value="submit" />
                <input type="hidden" name="draftKey" value={draftKey} />
                <FormControl>
                    <FormControl.Label>Summary</FormControl.Label>
                    <Textarea
                        ref={summary}
                        name="summary"
                        value={summaryText}
                        onChange={(event) => setSummaryText(event.target.value)}
                        block
                        resize="vertical"
                        rows={4}
                        placeholder="Leave a comment"
                        readOnly={busy}
                    />
                    <FormControl.Caption>Optional.</FormControl.Caption>
                </FormControl>
                <FormControl>
                    <FormControl.Label>Branch</FormControl.Label>
                    <Select name="workspace" defaultValue={delivery.workspace ?? ''} block disabled={busy}>
                        {WORKSPACE_OPTIONS.map(([value, label]) => (
                            <Select.Option key={value} value={value}>
                                {label}
                            </Select.Option>
                        ))}
                    </Select>
                </FormControl>
                {pullRequests && (
                    <FormControl>
                        <FormControl.Label>Pull request</FormControl.Label>
                        <Select name="pullRequest" defaultValue={null === delivery.pullRequest ? '' : String(delivery.pullRequest)} block disabled={busy}>
                            {PULL_REQUEST_OPTIONS.map(([value, label]) => (
                                <Select.Option key={value} value={value}>
                                    {label}
                                </Select.Option>
                            ))}
                        </Select>
                    </FormControl>
                )}
                <ActionErrorMessage result={submission.data} />
                <div className={styles.actions}>
                    <Button onClick={close} disabled={busy}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" loading={busy}>
                        Submit feedback
                    </Button>
                </div>
            </submission.Form>
        </AnchoredOverlay>
    );
}
