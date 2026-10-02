import type { CommitSha, DateTime, ObjectSha, RepoPath, Ulid } from './common';
import type { Location } from './location';

export const COMMIT_DIFF_FILE_STATUSES = ['added', 'modified', 'deleted', 'renamed', 'copied', 'type_changed'] as const;
export type CommitDiffFileStatus = (typeof COMMIT_DIFF_FILE_STATUSES)[number];

export type CommitDiffFile = {
    status: CommitDiffFileStatus;
    /** New path; the old path for deleted. */
    path: RepoPath;
    /** renamed and copied only. */
    oldPath: RepoPath | null;
    oldBlobSha: ObjectSha | null;
    newBlobSha: ObjectSha | null;
    oldMode: string | null;
    newMode: string | null;
    /** Null for binary files. */
    additions: number | null;
    deletions: number | null;
    binary: boolean;
    /** A side is above 1 MiB; no diff shown. */
    tooLarge: boolean;
    touchesAnchor: boolean;
};

export type CommitDiff = {
    requestId: Ulid;
    commitSha: CommitSha;
    /** Null for a root commit. */
    parentSha: CommitSha | null;
    subject: string;
    message: string;
    authorName: string;
    authorEmail: string;
    committedAt: DateTime;
    location: Location | null;
    files: CommitDiffFile[];
    truncated: boolean;
};
