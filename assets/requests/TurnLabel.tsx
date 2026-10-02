import { Label } from '@primer/react';
import type { Awaiting } from '../api/types/common';

export function TurnLabel({ awaiting }: { awaiting: Awaiting }) {
    switch (awaiting) {
        case 'user':
            return (
                <Label variant="attention" data-testid="turn-label">
                    Waiting for you
                </Label>
            );
        case 'agent':
            return (
                <Label variant="secondary" data-testid="turn-label">
                    Waiting for the agent
                </Label>
            );
        default:
            return null;
    }
}
