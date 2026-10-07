import type { CommitSha, DateTime, ObjectSha, RepoPath, Ulid } from './common';

/** V17: the commit that last changed each line of a file, up to the review commit. */
export type Blame = {
    reviewId: Ulid;
    commitSha: CommitSha;
    path: RepoPath;
    blobSha: ObjectSha;
    ignoreRevsFile: boolean;
    /** Ascending and without gaps, from line 1 to the last line. */
    ranges: BlameRange[];
    /** Each commit of `ranges` once, in the order of its first range. */
    commits: BlameCommit[];
};

export type BlameRange = { startLine: number; endLine: number; commitSha: CommitSha };

export type BlameCommit = { sha: CommitSha; authorName: string; authoredAt: DateTime; summary: string };
