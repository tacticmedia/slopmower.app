import { KebabHorizontalIcon } from '@primer/octicons-react';
import { ActionList, ActionMenu, IconButton } from '@primer/react';
import { useRef, useState } from 'react';
import type { Review } from '../api/types/review';
import { CarryOverDialog } from './CarryOverDialog';
import { CloseReviewDialog } from './CloseReviewDialog';
import { DeleteReviewDialog } from './DeleteReviewDialog';

type ReviewDialog = 'carry_over' | 'close' | 'delete';

/** "Carry over requests" and "Close review" only while the review is open. */
export function ReviewActionsMenu({ review, draftCount }: { review: Review; draftCount: number }) {
    const [dialog, setDialog] = useState<ReviewDialog | null>(null);
    const anchor = useRef<HTMLButtonElement>(null);
    const open = 'open' === review.state;
    const closeDialog = (): void => setDialog(null);

    return (
        <>
            <ActionMenu anchorRef={anchor}>
                <ActionMenu.Anchor>
                    <IconButton icon={KebabHorizontalIcon} aria-label="Review actions" />
                </ActionMenu.Anchor>
                <ActionMenu.Overlay align="end">
                    <ActionList>
                        {open && <ActionList.Item onSelect={() => setDialog('carry_over')}>Carry over requests</ActionList.Item>}
                        {open && <ActionList.Item onSelect={() => setDialog('close')}>Close review</ActionList.Item>}
                        {open && <ActionList.Divider />}
                        <ActionList.Item variant="danger" onSelect={() => setDialog('delete')}>
                            Delete review
                        </ActionList.Item>
                    </ActionList>
                </ActionMenu.Overlay>
            </ActionMenu>
            {open && 'carry_over' === dialog && <CarryOverDialog review={review} returnFocusRef={anchor} onClose={closeDialog} />}
            {open && 'close' === dialog && <CloseReviewDialog draftCount={draftCount} returnFocusRef={anchor} onClose={closeDialog} />}
            {'delete' === dialog && <DeleteReviewDialog repositoryId={review.repositoryId} returnFocusRef={anchor} onClose={closeDialog} />}
        </>
    );
}
