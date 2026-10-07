import { Label } from '@primer/react';

/**
 * A proposal waits for the user's decision. False shows nothing.
 */
export function PlanToApproveLabel({ pending }: { pending: boolean }) {
    return pending ? (
        <Label variant="attention" data-testid="plan-to-approve">
            Plan to approve
        </Label>
    ) : null;
}
