import { Label } from '@primer/react';
import type { KnownRequestType, RequestType } from '../api/types/common';

export const REQUEST_TYPE_NAMES: Record<KnownRequestType, string> = { change: 'Change', question: 'Question', rule: 'Rule' };

/**
 * Change, the default, shows nothing. An unknown type shows as its value.
 */
export function RequestTypeLabel({ type }: { type: RequestType }) {
    return 'change' === type ? null : <Label data-testid="request-type">{REQUEST_TYPE_NAMES[type as KnownRequestType] ?? type}</Label>;
}
