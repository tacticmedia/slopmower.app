import { Button, Checkbox, FormControl } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { useState } from 'react';
import type { Ulid } from '../api/types/common';
import type { SuggestionButton } from './allowedActions';
import { NoteDialog } from './NoteDialog';
import styles from './Requests.module.css';
import { useRequestCommand } from './useRequestCommand';

/**
 * "Accept" sends Q21 at once, "Dismiss" sends Q22 with an optional note. The writes run in the action of the request
 * detail route, also from the code view.
 */
export function SuggestionActions({ requestId, buttons }: { requestId: Ulid; buttons: readonly SuggestionButton[] }) {
    const { busy, sent, error, dialog, opener, send, openDialog, closeDialog } = useRequestCommand<'dismiss_suggestion'>(`/requests/${encodeURIComponent(requestId)}`);
    const [requireApproval, setRequireApproval] = useState(true);

    if (0 === buttons.length) {
        return null;
    }

    return (
        <div className={styles.requestActions} data-testid="suggestion-actions">
            <div className={styles.buttons}>
                {buttons.includes('accept_suggestion') && (
                    <>
                        <Button variant="primary" onClick={() => send({ intent: 'accept_suggestion', input: { requireApproval } })} loading={'accept_suggestion' === sent} disabled={busy}>
                            Accept
                        </Button>
                        <FormControl disabled={busy}>
                            <Checkbox checked={requireApproval} onChange={(event) => setRequireApproval(event.target.checked)} />
                            <FormControl.Label>Ask for a plan first</FormControl.Label>
                        </FormControl>
                    </>
                )}
                {buttons.includes('dismiss_suggestion') && (
                    <Button onClick={(event) => openDialog('dismiss_suggestion', event)} disabled={busy}>
                        Dismiss
                    </Button>
                )}
            </div>
            {null === dialog && null !== error && (
                <InlineMessage variant="critical" role="alert" data-testid="action-error">
                    {error}
                </InlineMessage>
            )}
            {null !== dialog && (
                <NoteDialog
                    title="Dismiss the suggestion"
                    subtitle="The suggestion closes as won't do."
                    fieldLabel="Note"
                    rule="optional"
                    submitLabel="Dismiss"
                    buttonType="danger"
                    busy={busy}
                    error={error}
                    onSubmit={(text) => send({ intent: 'dismiss_suggestion', input: { note: '' === text ? null : text } })}
                    onClose={closeDialog}
                    returnFocusRef={opener}
                />
            )}
        </div>
    );
}
