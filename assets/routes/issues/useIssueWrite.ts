import { useRef, useState, useTransition } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import type { IssueIntent } from './issueData';

export type IssueWrite = {
    busy: boolean;
    /** The message of the server for the last write, until the next write or `setOpen`. */
    error: string | null;
    /** The editor or the dialog of the write. It closes when the write succeeds and stays when it fails. */
    open: boolean;
    setOpen(open: boolean): void;
    /** False while another write of this hook runs. */
    send(intent: IssueIntent): boolean;
};

/**
 * A write of the issue page through its route action. The fetcher state does not change while the transition runs, so
 * `busy` comes from the transition.
 */
export function useIssueWrite(initiallyOpen: () => boolean = () => false): IssueWrite {
    const fetcher = useFetcher<ActionResult>();
    const [busy, startWrite] = useTransition();
    // A quick second click runs before `busy` renders, as in useSubmitOnce.
    const sending = useRef(false);
    const [open, setOpen] = useState(initiallyOpen);
    const [sent, setSent] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (sent && !busy) {
        setSent(false);
        const failure = fetcher.data?.error ?? null;
        setError(failure);
        if (null === failure) {
            setOpen(false);
        }
    }

    return {
        busy,
        error,
        open,
        setOpen: (next) => {
            if (!busy) {
                setError(null);
                setOpen(next);
            }
        },
        send: (intent) => {
            if (sending.current) {
                return false;
            }
            sending.current = true;
            setSent(true);
            setError(null);
            startWrite(async () => {
                try {
                    await fetcher.submit(intent, { method: 'post', encType: 'application/json' });
                } finally {
                    sending.current = false;
                }
            });

            return true;
        },
    };
}
