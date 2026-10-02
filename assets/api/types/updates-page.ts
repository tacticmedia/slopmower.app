import type { EventSeq, ReviewState, Ulid } from './common';
import type { Event } from './event';
import type { RequestSummary } from './request-summary';

export type UpdatedRequest = {
    request: RequestSummary;
    newEvents: Event[];
    /** Older unseen events left out in null-cursor mode. */
    omittedEvents: number;
};

export type UpdatesPage = {
    reviewId: Ulid;
    reviewState: ReviewState;
    /** Pass as cursor next time. */
    cursor: EventSeq;
    /** More events wait: call again at once with cursor. */
    hasMore: boolean;
    awaitingTotal: number;
    requests: UpdatedRequest[];
    /** review.started, review.closed, batch.submitted. */
    reviewEvents: Event[];
};
