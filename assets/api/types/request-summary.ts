import type { Actor } from './actor';
import type { AnchorSummary } from './anchor';
import type { Awaiting, DateTime, EventSeq, RequestStatus, Resolution, Ulid } from './common';

/** Only an in-progress request has a claim. */
export type RequestClaim =
    | { status: 'in_progress'; claimedBy: Actor; claimedAt: DateTime }
    | { status: Exclude<RequestStatus, 'in_progress'>; claimedBy: null; claimedAt: null };

export type RequestSummary = {
    id: Ulid;
    reviewId: Ulid;
    repositoryId: Ulid;
    awaiting: Awaiting;
    resolution: Resolution | null;
    /** verified, or wont_do set by the user. */
    closed: boolean;
    /** Null for a general request. */
    anchor: AnchorSummary | null;
    /** First non-blank line of the body, at most 200 characters. */
    summary: string;
    author: Actor;
    commentCount: number;
    commitCount: number;
    pendingCommitCount: number;
    /** Null until computed, when the clone is gone, and for a general request. */
    outdated: boolean | null;
    createdAt: DateTime;
    updatedAt: DateTime;
    /** seq of the last entry of the request thread. */
    lastEventSeq: EventSeq;
} & RequestClaim;
