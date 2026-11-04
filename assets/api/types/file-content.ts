import type { CommitSha, ObjectSha, RepoPath, Ulid } from './common';

export const FILE_CONTENT_TYPES = ['file', 'symlink'] as const;
export type FileContentType = (typeof FILE_CONTENT_TYPES)[number];

/** Metadata only. The bytes come from rawUrl, or for a browser repository from GitHub: the fields that need the blob are null then. */
export type FileContent = {
    reviewId: Ulid;
    commitSha: CommitSha;
    path: RepoPath;
    blobSha: ObjectSha;
    mode: string;
    type: FileContentType;
    sizeBytes: number;
    /** Highlighting language by file name. Open set. */
    language: string | null;
    /** NUL byte in the first 8000 bytes. */
    binary: boolean | null;
    tooLarge: boolean;
    renderable: boolean | null;
    /** Null when not renderable. */
    lineCount: number | null;
    symlinkTarget: string | null;
    /** A URI reference: it can be relative. */
    rawUrl: string | null;
};
