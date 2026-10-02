import type { Actor } from './actor';
import type { Anchor } from './anchor';
import type { CommitLink } from './commit-link';
import type { Action, Awaiting, CommitSha, DateTime, EventSeq, Markdown, RepoPath, Resolution, ReviewState, Ulid } from './common';
import type { Location } from './location';
import type { RequestClaim } from './request-summary';
import type { ThreadEntry } from './thread-entry';

export type CodeExcerpt = {
    targetSha: CommitSha;
    path: RepoPath;
    startLine: number;
    endLine: number;
    /** Raw lines without line endings. */
    lines: string[];
    /** True when cut at 400 lines. */
    truncated: boolean;
};

export type Request = {
    id: Ulid;
    reviewId: Ulid;
    repositoryId: Ulid;
    awaiting: Awaiting;
    resolution: Resolution | null;
    closed: boolean;
    anchor: Anchor;
    summary: string;
    author: Actor;
    commentCount: number;
    commitCount: number;
    pendingCommitCount: number;
    outdated: boolean | null;
    createdAt: DateTime;
    updatedAt: DateTime;
    lastEventSeq: EventSeq;
    body: Markdown;
    reviewState: ReviewState;
    reviewCommitSha: CommitSha;
    /** At `at` when given, else at the default-branch head. */
    location: Location | null;
    /** Only when `context` was given. */
    excerpt: CodeExcerpt | null;
    /** Ascending seq; starts with request.opened. */
    thread: ThreadEntry[];
    commits: CommitLink[];
    /** For the caller. Empty when the review is closed. */
    allowedActions: Action[];
} & RequestClaim;
