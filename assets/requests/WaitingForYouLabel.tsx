import { Label } from '@primer/react';

/**
 * `awaiting` is `user`. False shows nothing.
 */
export function WaitingForYouLabel({ waiting }: { waiting: boolean }) {
    return waiting ? (
        <Label variant="attention" data-testid="waiting-for-you">
            Waiting for you
        </Label>
    ) : null;
}
