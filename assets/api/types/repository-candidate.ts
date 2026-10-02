import type { Ulid } from './common';
import type { LocalDirectory } from './repository';

type SourceFields =
    | {
          source: 'github';
          github: {
              repositoryId: number;
              installationId: number;
              /** Account that installed the App. */
              accountLogin: string;
              owner: string;
              private: boolean;
              defaultBranch: string;
              htmlUrl: string;
          };
          local: null;
      }
    | {
          source: 'local';
          github: null;
          local: {
              directory: LocalDirectory;
              bare: boolean;
              /** Null for a detached HEAD. */
              headBranch: string | null;
          };
      };

export type RepositoryCandidate = {
    name: string;
    connectedRepositoryId: Ulid | null;
} & SourceFields;
