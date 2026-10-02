import type { Actor } from './actor';
import type { CommitSha, DateTime, EventSeq, RequestStatus, ThreadEntryType, Ulid } from './common';

export type ThreadEntry = {
    seq: EventSeq;
    eventId: Ulid;
    type: ThreadEntryType;
    actor: Actor;
    occurredAt: DateTime;
    /** Body, comment, note, or reason, depending on the type. */
    body: string | null;
    /** request.commented only. */
    commentId: Ulid | null;
    /** True only for an agent comment that asks the user. */
    question: boolean;
    batchId: Ulid | null;
    /** commit.* only. */
    commitSha: CommitSha | null;
    statusAfter: RequestStatus;
};
