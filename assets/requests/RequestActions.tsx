import { Button } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import type { Action } from '../api/types/common';
import { buttonLabel, type RequestButton, requestButtons } from './allowedActions';
import { NoteDialog, type NoteDialogSpec } from './NoteDialog';
import type { RequestCommandIntent } from './requestIntent';
import styles from './Requests.module.css';
import { useRequestCommand } from './useRequestCommand';

type OneClickButton = 'verify' | 'set_plan_requirement';

type DialogButton = Exclude<RequestButton, OneClickButton>;

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

function oneClickIntent(button: OneClickButton, requireApproval: boolean): RequestCommandIntent {
    return 'verify' === button ? { intent: 'verify', input: {} } : { intent: 'set_plan_requirement', input: { requireApproval: !requireApproval } };
}

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
export function RequestActions({ allowedActions, requireApproval }: { allowedActions: readonly Action[]; requireApproval: boolean }) {
    const { busy, sent, error, dialog, opener, send, openDialog, closeDialog } = useRequestCommand<DialogButton>();
    const buttons = requestButtons(allowedActions);

    if (0 === buttons.length) {
        return null;
    }

    return (
        <div className={styles.requestActions} data-testid="request-actions">
            <div className={styles.buttons}>
                {buttons.map((button) =>
                    'verify' === button || 'set_plan_requirement' === button ? (
                        <Button
                            key={button}
                            variant={'verify' === button ? 'primary' : 'default'}
                            onClick={() => send(oneClickIntent(button, requireApproval))}
                            loading={button === sent}
                            disabled={busy}
                        >
                            {buttonLabel(button, requireApproval)}
                        </Button>
                    ) : (
                        <Button key={button} onClick={(event) => openDialog(button, event)} disabled={busy}>
                            {buttonLabel(button, requireApproval)}
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
                    onClose={closeDialog}
                    returnFocusRef={opener}
                />
            )}
        </div>
    );
}
