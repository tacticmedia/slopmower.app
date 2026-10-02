import type { CommitSha, ObjectSha, RepoPath, SymbolKind } from './common';

/** 1-based, inclusive. */
export type LineRange = {
    startLine: number;
    endLine: number;
};

export type SymbolRef = {
    language: string;
    kind: SymbolKind;
    fqn: string;
};

export type ContentSnapshot = {
    /** Normalized lines; above 200 lines, the first 100 and the last 100. */
    lines: string[];
    truncated: boolean;
    lineCount: number;
    before: string[];
    after: string[];
    /** `sha256:` + hex digest of the region in comparison form. */
    normalizedHash: string;
};

export type Anchor =
    | {
          kind: 'line' | 'range';
          path: RepoPath;
          commitSha: CommitSha;
          blobSha: ObjectSha;
          range: LineRange;
          symbol: null;
          snapshot: ContentSnapshot;
      }
    | {
          kind: 'symbol';
          path: RepoPath;
          commitSha: CommitSha;
          blobSha: ObjectSha;
          range: LineRange;
          symbol: SymbolRef;
          snapshot: ContentSnapshot;
      }
    | {
          kind: 'file';
          path: RepoPath;
          commitSha: CommitSha;
          blobSha: ObjectSha;
          range: null;
          symbol: null;
          snapshot: null;
      };

export type AnchorSummary =
    | {
          kind: 'line' | 'range';
          path: RepoPath;
          range: LineRange;
          symbol: null;
      }
    | {
          kind: 'symbol';
          path: RepoPath;
          range: LineRange;
          symbol: SymbolRef;
      }
    | {
          kind: 'file';
          path: RepoPath;
          range: null;
          symbol: null;
      };

export type AnchorInput =
    | {
          kind: 'line' | 'range';
          path: RepoPath;
          /** line: startLine = endLine. range: endLine > startLine. */
          range: LineRange;
          symbol?: null;
      }
    | {
          kind: 'symbol';
          path: RepoPath;
          range?: null;
          /** Must match exactly one symbol of the file. */
          symbol: { fqn: string };
      }
    | {
          kind: 'file';
          path: RepoPath;
          range?: null;
          symbol?: null;
      };
