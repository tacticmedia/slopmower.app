import type { Actor } from './actor';
import type { CommitSha, DateTime, EventSeq, PullRequestUrl, RequestStatus, ThreadEntryType, Ulid, UnifiedDiff } from './common';

export type ThreadEntry = {
    seq: EventSeq;
    eventId: Ulid;
    type: ThreadEntryType;
    actor: Actor;
    occurredAt: DateTime;
    /** Body, comment, plan, note, or reason, depending on the type. */
    body: string | null;
    /** request.proposed only, when the agent attached one. */
    diff: UnifiedDiff | null;
    /** request.commented only. */
    commentId: Ulid | null;
    /** True only for an agent comment that asks the user. */
    question: boolean;
    batchId: Ulid | null;
    /** commit.* only. */
    commitSha: CommitSha | null;
    /** pull_request.linked and pull_request.unlinked. */
    pullRequestUrl: PullRequestUrl | null;
    statusAfter: RequestStatus;
};
