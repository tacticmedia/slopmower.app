import type { LocationStatus, OrphanReason, RequestStatus, Ulid } from './common';
import type { RequestSummary } from './request-summary';

export const CARRY_OVER_OUTCOMES = ['carried', 'already_carried', 'not_carried'] as const;
export type CarryOverOutcome = (typeof CARRY_OVER_OUTCOMES)[number];

export type CarryOverItem = {
    /** The source request, unchanged. */
    source: RequestSummary;
    /** At the target review commit, when this call located the anchor. */
    location: LocationStatus | null;
} & (
    | { outcome: 'carried' | 'already_carried'; requestId: Ulid; reason: null }
    | { outcome: 'not_carried'; requestId: null; reason: RequestStatus | OrphanReason }
);

export type CarryOverResult = {
    reviewId: Ulid;
    fromReviewId: Ulid;
    /** Sorted by the source request id. */
    items: CarryOverItem[];
};
