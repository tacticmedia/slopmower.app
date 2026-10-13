import type { Actor } from './actor';
import type { Action, CommitSha, DateTime, ErrorCode, RequestStatus, Ulid, UsageLimit } from './common';

export const REPOSITORY_UNAVAILABLE_REASONS = ['cloning', 'clone_failed', 'fetch_failed', 'removed'] as const;
export type RepositoryUnavailableReason = (typeof REPOSITORY_UNAVAILABLE_REASONS)[number];

export type InvalidInputDetails = {
    violations: {
        /** Dotted input path, for example `anchor.range.endLine`; empty for the whole input. */
        field: string;
        message: string;
    }[];
};

export type InvalidTransitionDetails = {
    requestId: Ulid;
    action: Action;
    status: RequestStatus;
    claimedBy: Actor | null;
    allowedActions: Action[];
};

export type ReviewClosedDetails = {
    reviewId: Ulid;
    closedAt: DateTime;
};

export type CommitsNotAvailableDetails = {
    pendingCommits: CommitSha[];
};

export type CommitNotFoundDetails = {
    /** The SHA or ref as the caller gave it. */
    commit: string;
};

export type RepositoryUnavailableDetails = {
    repositoryId: Ulid;
    reason: RepositoryUnavailableReason;
};

export type RateLimitedDetails = {
    /** Equals the Retry-After header of a REST response. */
    retryAfterSeconds: number;
    /** The calls that the refusing limiter allows in its interval. */
    limit: number;
};

export type LimitExceededDetails = {
    limit: UsageLimit;
    /** Bytes for `repository_size` and `tenant_storage`. */
    max: number;
    /** The use before the call. */
    current: number;
};

export type ErrorDetails =
    | InvalidInputDetails
    | InvalidTransitionDetails
    | ReviewClosedDetails
    | CommitsNotAvailableDetails
    | CommitNotFoundDetails
    | RepositoryUnavailableDetails
    | RateLimitedDetails
    | LimitExceededDetails;

export type ErrorBody = {
    error: {
        code: ErrorCode;
        /** English and not stable: branch on code. */
        message: string;
        /** The shape depends on code. */
        details: ErrorDetails | null;
    };
};
