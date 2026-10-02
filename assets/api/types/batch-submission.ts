import type { DateTime, EventSeq, Ulid } from './common';
import type { RequestSummary } from './request-summary';

export type BatchSubmission = {
    batchId: Ulid;
    reviewId: Ulid;
    summary: string | null;
    submittedAt: DateTime;
    /** seq of batch.submitted, the last event of the batch. */
    seq: EventSeq;
    openedRequests: RequestSummary[];
    replies: { requestId: Ulid; commentId: Ulid }[];
};
