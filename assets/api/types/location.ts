import type { LineRange } from './anchor';
import type { CommitSha, LocationStatus, OrphanReason, RepoPath } from './common';

export const LOCATION_TARGETS = ['default_branch', 'requested', 'linked_commit'] as const;
export type LocationTarget = (typeof LOCATION_TARGETS)[number];

/** Computed and cached, never an event. */
export type Location =
    | {
          target: LocationTarget;
          targetSha: CommitSha;
          status: Exclude<LocationStatus, 'orphaned'>;
          path: RepoPath;
          /** Null for a file anchor. */
          range: LineRange | null;
          /** 1 for exact and shifted. */
          confidence: number;
          reason: null;
      }
    | {
          target: LocationTarget;
          targetSha: CommitSha;
          status: 'orphaned';
          path: null;
          range: null;
          /** Always 0. */
          confidence: number;
          reason: OrphanReason;
      };
