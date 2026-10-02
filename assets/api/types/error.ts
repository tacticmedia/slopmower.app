import type { Actor } from './actor';
import type { Action, CommitSha, DateTime, ErrorCode, RequestStatus, Ulid } from './common';

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

export type ErrorDetails =
    | InvalidInputDetails
    | InvalidTransitionDetails
    | ReviewClosedDetails
    | CommitsNotAvailableDetails
    | CommitNotFoundDetails
    | RepositoryUnavailableDetails;

export type ErrorBody = {
    error: {
        code: ErrorCode;
        /** English and not stable: branch on code. */
        message: string;
        /** The shape depends on code. */
        details: ErrorDetails | null;
    };
};
