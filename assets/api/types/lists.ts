import type { ApiToken } from './api-token';
import type { CommitSha, Cursor, DateTime, ObjectSha, RepoPath, ReviewState, Ulid } from './common';
import type { Declaration } from './declaration';
import type { Draft } from './draft';
import type { Repository } from './repository';
import type { RepositoryCandidate } from './repository-candidate';
import type { RequestSummary } from './request-summary';
import type { Review } from './review';
import type { Symbol } from './symbol';
import type { TreeEntry } from './tree-entry';

/** Null on the last page. */
export type NextCursor = Cursor | null;

export type RepositoryPage = {
    items: Repository[];
    nextCursor: NextCursor;
};

export type CandidateList = {
    /** At most 1,000 items. */
    items: RepositoryCandidate[];
    truncated: boolean;
    localEnabled: boolean;
    githubListedAt: DateTime | null;
    githubConfigureUrl: string | null;
};

export type ReviewPage = {
    repository: Repository;
    items: Review[];
    nextCursor: NextCursor;
};

export type RequestPage = {
    reviewId: Ulid;
    reviewState: ReviewState;
    total: number;
    items: RequestSummary[];
    nextCursor: NextCursor;
};

export type TreeListing = {
    reviewId: Ulid;
    commitSha: CommitSha;
    /** Empty for the root. */
    dir: string;
    recursive: boolean;
    items: TreeEntry[];
    nextCursor: NextCursor;
};

export type SymbolList = {
    reviewId: Ulid;
    path: RepoPath;
    blobSha: ObjectSha;
    language: string | null;
    parseError: boolean;
    items: Symbol[];
};

export type DeclarationList = {
    reviewId: Ulid;
    items: Declaration[];
};

export type DraftList = {
    reviewId: Ulid;
    items: Draft[];
};

export type TokenList = {
    items: ApiToken[];
};

export type ViewedFiles = {
    reviewId: Ulid;
    paths: RepoPath[];
};

export type MercureAuthorization = {
    hubUrl: string;
    /** Topic selectors in the subscriber JWT. */
    topics: string[];
    expiresAt: DateTime;
};
