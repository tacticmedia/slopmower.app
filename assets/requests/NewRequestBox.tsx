import { PlusIcon } from '@primer/octicons-react';
import { Button } from '@primer/react';
import { useState, useTransition } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import type { Draft } from '../api/types/draft';
import { CommentBox } from '../comments/CommentBox';
import type { CommentIntent } from '../comments/commentIntent';
import { reviewLabel } from '../comments/draftView';
import shared from './Requests.module.css';

/**
 * Opens a general request with Q2, or adds it to the pending review with D2. The box closes when the write succeeds
 * and keeps the text when it fails.
 */
export function NewRequestBox({ drafts }: { drafts: readonly Draft[] }) {
    const fetcher = useFetcher<ActionResult>();
    const [writing, startWrite] = useTransition();
    const [open, setOpen] = useState(false);
    const [sent, setSent] = useState(false);
    // The fetcher keeps the result of an earlier box, so only a submit since the box opened shows an error.
    const [submitted, setSubmitted] = useState(false);
    const error = writing || !submitted ? null : (fetcher.data?.error ?? null);

    if (sent && !writing) {
        setSent(false);
        if (null === error) {
            setOpen(false);
        }
    }

    if (!open) {
        return (
            <Button
                size="small"
                leadingVisual={PlusIcon}
                onClick={() => {
                    setOpen(true);
                    setSubmitted(false);
                }}
            >
                New request
            </Button>
        );
    }

    const submit = (intent: CommentIntent): boolean => {
        if (writing) {
            return false;
        }
        setSent(true);
        setSubmitted(true);
        startWrite(async () => {
            await fetcher.submit(intent, { method: 'post', encType: 'application/json' });
        });

        return true;
    };

    return (
        <div className={shared.newRequest}>
            <CommentBox
                label="New request"
                labelVisible
                submitLabel="Add single comment"
                onSubmit={(body, requireApproval) => submit({ intent: 'open', input: { anchor: null, body, requireApproval } })}
                alternative={{
                    label: reviewLabel(drafts),
                    onSubmit: (body, requireApproval) => submit({ intent: 'draft', input: { kind: 'new_request', anchor: null, body, requireApproval } }),
                }}
                askForPlan
                onCancel={() => setOpen(false)}
                error={error}
                busy={writing}
                focusOnMount
            />
        </div>
    );
}
