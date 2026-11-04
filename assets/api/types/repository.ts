import type { CodeAccess, CommitSha, DateTime, Ulid } from './common';

/** Name of a direct child directory of APP_LOCAL_REPOSITORIES_DIR. */
export type LocalDirectory = string;

export const SYNC_STATES = ['cloning', 'failed', 'idle', 'fetching', 'unavailable'] as const;
export type SyncState = (typeof SYNC_STATES)[number];

export type Sync = {
    state: SyncState;
    lastFetchedAt: DateTime | null;
    /** Message of the last failed clone, fetch, or head sync; null after a success. */
    lastError: string | null;
    defaultBranchHead: CommitSha | null;
};

type SourceFields =
    | {
          source: 'github';
          codeAccess: CodeAccess;
          github: {
              repositoryId: number;
              installationId: number;
              owner: string;
              private: boolean;
              htmlUrl: string;
              checkEveryPullRequest: boolean;
          };
          local: null;
      }
    | {
          source: 'local';
          codeAccess: 'clone';
          github: null;
          local: { directory: LocalDirectory };
      };

type AccessFields = { access: 'active'; sync: Sync } | { access: 'removed'; sync: Sync & { state: 'unavailable' } };

export type Repository = {
    id: Ulid;
    /** github: owner/name. local: directory name. Unique per tenant. */
    name: string;
    /** Null until the first clone finishes. */
    defaultBranch: string | null;
    openReviewCount: number;
    createdAt: DateTime;
} & SourceFields &
    AccessFields;
