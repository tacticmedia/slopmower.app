import { Label } from '@primer/react';

/**
 * Architecture section 5.3: the location at the default-branch head is changed or orphaned. Null shows nothing.
 */
export function OutdatedLabel({ outdated }: { outdated: boolean | null }) {
    return true === outdated ? (
        <Label variant="attention" data-testid="outdated-label">
            Outdated
        </Label>
    ) : null;
}
