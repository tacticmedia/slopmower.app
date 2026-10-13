import { type MouseEvent, type RefObject, useRef, useState, useTransition } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import type { RequestCommandIntent } from './requestIntent';

export type RequestCommand<Dialog extends string> = {
    busy: boolean;
    /** The intent of the write that runs. */
    sent: RequestCommandIntent['intent'] | null;
    /** The error of the last write, also while its dialog is open. */
    error: string | null;
    dialog: Dialog | null;
    /** The button that opened the dialog, which gets the focus back. */
    opener: RefObject<HTMLElement | null>;
    send(intent: RequestCommandIntent): void;
    openDialog(dialog: Dialog, event: MouseEvent<HTMLElement>): void;
    closeDialog(): void;
};

/**
 * The user commands of the request detail. One write runs at a time, and a dialog closes when its write succeeds. The
 * fetcher state does not change while the transition runs, so `busy` comes from the transition.
 *
 * @param action The route whose action runs the write, for a command outside the request detail
 */
export function useRequestCommand<Dialog extends string>(action?: string): RequestCommand<Dialog> {
    const fetcher = useFetcher<ActionResult>();
    const [busy, startCommand] = useTransition();
    // A quick second click runs before `busy` renders, as in useSubmitOnce.
    const sending = useRef(false);
    const opener = useRef<HTMLElement | null>(null);
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const [sent, setSent] = useState<RequestCommandIntent['intent'] | null>(null);
    const [error, setError] = useState<string | null>(null);

    if (null !== sent && !busy) {
        setSent(null);
        const failure = fetcher.data?.error ?? null;
        setError(failure);
        if (null === failure) {
            setDialog(null);
        }
    }

    return {
        busy,
        sent,
        error,
        dialog,
        opener,
        send: (intent) => {
            if (sending.current) {
                return;
            }
            sending.current = true;
            setSent(intent.intent);
            setError(null);
            startCommand(async () => {
                try {
                    await fetcher.submit(intent, { method: 'post', encType: 'application/json', action });
                } finally {
                    sending.current = false;
                }
            });
        },
        openDialog: (next, event) => {
            if (!busy) {
                opener.current = event.currentTarget;
                setError(null);
                setDialog(next);
            }
        },
        closeDialog: () => {
            setDialog(null);
            setError(null);
        },
    };
}
