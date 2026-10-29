import { PlusIcon } from '@primer/octicons-react';
import { Button } from '@primer/react';
import { useState, useTransition } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import { CommentBox } from '../comments/CommentBox';
import type { CommentIntent } from '../comments/commentIntent';
import shared from './Requests.module.css';

/**
 * Adds a general request to the pending feedback with D2. The box closes when the write succeeds and keeps the text
 * when it fails.
 */
export function NewRequestBox() {
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
                submitLabel="Add comment"
                onSubmit={(text) => submit({ intent: 'draft', input: { kind: 'new_request', anchor: null, ...text } })}
                askForPlan
                titled
                onCancel={() => setOpen(false)}
                error={error}
                busy={writing}
                focusOnMount
            />
        </div>
    );
}
