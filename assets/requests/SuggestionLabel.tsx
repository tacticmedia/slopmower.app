import { Label } from '@primer/react';

/**
 * A suggestion of the agent waits for the user's decision. False shows nothing.
 */
export function SuggestionLabel({ pending }: { pending: boolean }) {
    return pending ? (
        <Label variant="attention" data-testid="suggestion">
            Suggestion
        </Label>
    ) : null;
}
