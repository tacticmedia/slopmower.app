import { Button } from '@primer/react';
import { useState } from 'react';
import type { Repository } from '../api/types/repository';
import { TypedConfirmationDialog } from './TypedConfirmationDialog';

/**
 * R6. The settings loader runs again after the delete, so the repository leaves the list.
 */
export function DeleteRepositoryDialog({ repository }: { repository: Repository }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button size="small" variant="danger" onClick={() => setOpen(true)}>
                Delete data
            </Button>
            {open && (
                <TypedConfirmationDialog
                    title={`Delete the data of ${repository.name}`}
                    expected={repository.name}
                    confirmButtonContent="Delete data"
                    intent={(confirm) => ({ intent: 'delete-repository', repositoryId: repository.id, confirm })}
                    onClose={() => setOpen(false)}
                >
                    <p>
                        The reviews of this repository are deleted with their requests, comments, and pending comments, together with the symbol index, the
                        tokens for this repository, and the server clone. You cannot undo this. You can connect the repository again.
                    </p>
                </TypedConfirmationDialog>
            )}
        </>
    );
}
