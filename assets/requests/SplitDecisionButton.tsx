import { TriangleDownIcon } from '@primer/octicons-react';
import { ActionList, ActionMenu, Button, ButtonGroup, IconButton } from '@primer/react';
import { useConfirmDiscard } from '../comments/UnsavedCommentGuard';

type Props = {
    label: string;
    loading: boolean;
    disabled: boolean;
    /** `next` opens the next request that waits for the user after the write. */
    onSend(next: boolean): void;
};

/** A primary decision of the request detail, with "<label> and go to the next one" in its menu. */
export function SplitDecisionButton({ label, loading, disabled, onSend }: Props) {
    const confirmDiscard = useConfirmDiscard();
    const sendAndGoNext = async (): Promise<void> => {
        if (await confirmDiscard()) {
            onSend(true);
        }
    };

    return (
        <ButtonGroup>
            <Button variant="primary" onClick={() => onSend(false)} loading={loading} disabled={disabled}>
                {label}
            </Button>
            <ActionMenu>
                <ActionMenu.Anchor>
                    <IconButton variant="primary" icon={TriangleDownIcon} aria-label={`More ${label.toLowerCase()} options`} disabled={disabled} />
                </ActionMenu.Anchor>
                <ActionMenu.Overlay align="end">
                    <ActionList>
                        <ActionList.Item onSelect={() => void sendAndGoNext()}>{label} and go to the next one</ActionList.Item>
                    </ActionList>
                </ActionMenu.Overlay>
            </ActionMenu>
        </ButtonGroup>
    );
}
