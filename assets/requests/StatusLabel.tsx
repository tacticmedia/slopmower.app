import { Label, type LabelProps } from '@primer/react';
import type { RequestStatus } from '../api/types/common';

const LABELS: Record<RequestStatus, { text: string; variant: NonNullable<LabelProps['variant']> }> = {
    open: { text: 'Open', variant: 'accent' },
    in_progress: { text: 'In progress', variant: 'attention' },
    ready_for_review: { text: 'Ready for review', variant: 'success' },
    verified: { text: 'Verified', variant: 'done' },
    reopened: { text: 'Reopened', variant: 'severe' },
    wont_do: { text: "Won't do", variant: 'secondary' },
};

export function StatusLabel({ status }: { status: RequestStatus }) {
    const { text, variant } = LABELS[status];

    return (
        <Label variant={variant} data-testid="status-label">
            {text}
        </Label>
    );
}
