import { Label, type LabelProps } from '@primer/react';
import type { RequestStatus } from '../api/types/common';
import { STATUS_TEXT } from './statusText';

const VARIANTS: Record<RequestStatus, NonNullable<LabelProps['variant']>> = {
    open: 'accent',
    in_progress: 'attention',
    ready_for_review: 'success',
    verified: 'done',
    reopened: 'severe',
    wont_do: 'secondary',
};

export function StatusLabel({ status }: { status: RequestStatus }) {
    return (
        <Label variant={VARIANTS[status]} data-testid="status-label">
            {STATUS_TEXT[status]}
        </Label>
    );
}
