import type { ObjectSha, RepoPath } from './common';

export const TREE_ENTRY_TYPES = ['directory', 'file', 'symlink', 'submodule'] as const;
export type TreeEntryType = (typeof TREE_ENTRY_TYPES)[number];

export type TreeEntry = {
    path: RepoPath;
    name: string;
    type: TreeEntryType;
    mode: string;
    sha: ObjectSha;
    /** Files and symlinks only. */
    sizeBytes: number | null;
};
