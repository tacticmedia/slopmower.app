import { Button } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import type { Action, Ulid } from '../api/types/common';
import { REQUEST_BUTTON_LABELS, type RequestButton, requestButtons } from './allowedActions';
import { NoteDialog, type NoteDialogSpec } from './NoteDialog';
import type { RequestCommandIntent } from './requestIntent';
import styles from './Requests.module.css';
import { SplitDecisionButton } from './SplitDecisionButton';
import { useRequestCommand } from './useRequestCommand';

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
 * The buttons follow `allowedActions` of Q3.
 */
export function RequestActions({ requestId, allowedActions }: { requestId: Ulid; allowedActions: readonly Action[] }) {
    const { busy, sent, error, dialog, opener, send, openDialog, closeDialog } = useRequestCommand<DialogButton>();
    const buttons = requestButtons(allowedActions);
    const noteKey = `request:${requestId}:${dialog}`;

    if (0 === buttons.length) {
        return null;
    }

    return (
        <div className={styles.requestActions} data-testid="request-actions">
            <div className={styles.buttons}>
                {buttons.map((button) =>
                    'verify' === button ? (
                        <SplitDecisionButton
                            key={button}
                            label={REQUEST_BUTTON_LABELS[button]}
                            onSend={(next) => send({ intent: 'verify', input: {}, next })}
                            loading={button === sent}
                            disabled={busy}
                        />
                    ) : (
                        <Button key={button} onClick={(event) => openDialog(button, event)} disabled={busy}>
                            {REQUEST_BUTTON_LABELS[button]}
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
                    draftKey={noteKey}
                    busy={busy}
                    error={error}
                    onSubmit={(text) => send({ ...dialogIntent(dialog, text), draftKey: noteKey })}
                    onClose={closeDialog}
                    returnFocusRef={opener}
                />
            )}
        </div>
    );
}
