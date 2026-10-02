import { Button } from '@primer/react';
import { useState } from 'react';
import type { Me } from '../api/types/me';
import { TypedConfirmationDialog } from './TypedConfirmationDialog';

/**
 * A2. The settings action loads /login as a new document after the delete, because the session has ended.
 */
export function DeleteAccountDialog({ user }: { user: Me['user'] }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button variant="danger" onClick={() => setOpen(true)}>
                Delete account
            </Button>
            {open && (
                <TypedConfirmationDialog
                    title="Delete account"
                    expected={user.login}
                    confirmButtonContent="Delete account"
                    intent={(confirm) => ({ intent: 'delete-account', confirm })}
                    onClose={() => setOpen(false)}
                >
                    <p>
                        Your account is deleted with all repositories, reviews, API tokens, and server clones. You are signed out at once. You cannot undo
                        this.
                    </p>
                    {'github' === user.signInMethod && <p>The GitHub App stays installed until you uninstall it on GitHub.</p>}
                </TypedConfirmationDialog>
            )}
        </>
    );
}
