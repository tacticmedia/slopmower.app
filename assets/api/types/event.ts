import type { Actor } from './actor';
import type { CommitSha, DateTime, EventSeq, EventType, PullRequestUrl, RequestStatus, Ulid, UnifiedDiff } from './common';

export type Event = {
    seq: EventSeq;
    eventId: Ulid;
    type: EventType;
    reviewId: Ulid;
    /** Set for request.*, commit.unlinked, and pull_request.unlinked. */
    requestId: Ulid | null;
    /** Every request the event concerns; [] for review events. */
    requestIds: Ulid[];
    actor: Actor;
    occurredAt: DateTime;
    body: string | null;
    diff: UnifiedDiff | null;
    commentId: Ulid | null;
    question: boolean;
    batchId: Ulid | null;
    /** commit.* and review.started. */
    commitSha: CommitSha | null;
    /** pull_request.linked and pull_request.unlinked. */
    pullRequestUrl: PullRequestUrl | null;
    /** Null when requestId is null. */
    statusAfter: RequestStatus | null;
};
