import { Button } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { type MouseEvent, useRef, useState, useTransition } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import type { Action } from '../api/types/common';
import { BUTTON_LABELS, type RequestButton, requestButtons } from './allowedActions';
import { NoteDialog, type NoteDialogSpec } from './NoteDialog';
import type { RequestCommandIntent } from './requestIntent';
import styles from './Requests.module.css';

type DialogButton = Exclude<RequestButton, 'verify'>;

const DIALOGS: Record<DialogButton, NoteDialogSpec> = {
    reopen: {
        title: 'Reopen',
        subtitle: 'The request goes back to the agent. Say what is still missing.',
        fieldLabel: 'Note',
        rule: 'required',
        submitLabel: 'Reopen',
        buttonType: 'primary',
    },
    mark_wont_do: {
        title: "Close as won't do",
        subtitle: 'The request closes without a change.',
        fieldLabel: 'Reason',
        rule: 'reason',
        submitLabel: "Close as won't do",
        buttonType: 'danger',
    },
    release: {
        title: 'Unassign',
        subtitle: 'The agent loses its claim. The request returns to its status before the claim.',
        fieldLabel: 'Note',
        rule: 'optional',
        submitLabel: 'Unassign',
        buttonType: 'danger',
    },
};

function dialogIntent(button: DialogButton, text: string): RequestCommandIntent {
    switch (button) {
        case 'reopen':
            return { intent: 'reopen', input: { note: text } };
        case 'mark_wont_do':
            return { intent: 'mark_wont_do', input: { reason: text } };
        case 'release':
            return { intent: 'release', input: { note: '' === text ? null : text } };
    }
}

/**
 * The buttons follow `allowedActions` of Q3. One command runs at a time; a dialog closes when its command succeeds.
 * The fetcher state does not change while the transition runs, so `busy` comes from the transition.
 */
export function RequestActions({ allowedActions }: { allowedActions: readonly Action[] }) {
    const fetcher = useFetcher<ActionResult>();
    const [busy, startCommand] = useTransition();
    const opener = useRef<HTMLElement | null>(null);
    const [dialog, setDialog] = useState<DialogButton | null>(null);
    const [sent, setSent] = useState<RequestButton | null>(null);
    const [error, setError] = useState<string | null>(null);
    const buttons = requestButtons(allowedActions);

    if (null !== sent && !busy) {
        setSent(null);
        const failure = fetcher.data?.error ?? null;
        setError(failure);
        if (null === failure) {
            setDialog(null);
        }
    }

    const send = (intent: RequestCommandIntent): void => {
        if (busy) {
            return;
        }
        setSent(intent.intent);
        setError(null);
        startCommand(async () => {
            await fetcher.submit(intent, { method: 'post', encType: 'application/json' });
        });
    };

    const openDialog = (button: DialogButton, event: MouseEvent<HTMLButtonElement>): void => {
        if (!busy) {
            opener.current = event.currentTarget;
            setError(null);
            setDialog(button);
        }
    };

    if (0 === buttons.length) {
        return null;
    }

    return (
        <div className={styles.requestActions} data-testid="request-actions">
            <div className={styles.buttons}>
                {buttons.map((button) =>
                    'verify' === button ? (
                        <Button key={button} variant="primary" onClick={() => send({ intent: 'verify', input: {} })} loading={'verify' === sent} disabled={busy}>
                            {BUTTON_LABELS[button]}
                        </Button>
                    ) : (
                        <Button key={button} onClick={(event) => openDialog(button, event)} disabled={busy}>
                            {BUTTON_LABELS[button]}
                        </Button>
                    ),
                )}
            </div>
            {null === dialog && null !== error && (
                <InlineMessage variant="critical" role="alert" data-testid="action-error">
                    {error}
                </InlineMessage>
            )}
            {null !== dialog && (
                <NoteDialog
                    key={dialog}
                    {...DIALOGS[dialog]}
                    busy={busy}
                    error={error}
                    onSubmit={(text) => send(dialogIntent(dialog, text))}
                    onClose={() => {
                        setDialog(null);
                        setError(null);
                    }}
                    returnFocusRef={opener}
                />
            )}
        </div>
    );
}
