import type { AnchorInput } from './anchor';
import type { TokenName } from './api-token';
import type { CommitSha, DateTime, Markdown, RepoPath, Ulid, UnifiedDiff } from './common';
import type { LocalDirectory } from './repository';

export type ConnectRepositoryInput =
    | { source: 'github'; githubRepositoryId: number; directory?: LocalDirectory | null }
    | { source: 'local'; githubRepositoryId?: number | null; directory: LocalDirectory };

export type StartReviewInput = {
    /** Default: the default branch. */
    ref?: string | null;
    title?: string | null;
};

export type CloseReviewInput = {
    note?: string | null;
};

export type CarryOverInput = {
    /** Another review of the same repository. */
    fromReviewId: Ulid;
};

export type OpenRequestInput = {
    /** Absent or null opens a general request. */
    anchor?: AnchorInput | null;
    body: Markdown;
    /** "Ask for a plan first". Default false. */
    requireApproval?: boolean | null;
};

export type CommentInput = {
    body: Markdown;
    /** Agents only. */
    question?: boolean;
};

export type OptionalNoteInput = {
    note?: string | null;
};

export type RequiredNoteInput = {
    note: Markdown;
};

export type WontDoInput = {
    /** 20 or more characters after trimming. */
    reason: string;
};

export type ProposeSolutionInput = {
    plan: Markdown;
    diff?: UnifiedDiff | null;
};

export type ApproveProposalInput = {
    /** Request.proposal.eventId. */
    proposalEventId: Ulid;
    note?: string | null;
};

export type RejectProposalInput = {
    proposalEventId: Ulid;
    note: Markdown;
};

export type LinkCommitsInput = {
    commitSha: CommitSha;
    requestIds: Ulid[];
    note?: string | null;
};

export type CreateDraftInput =
    | { kind: 'new_request'; anchor?: AnchorInput | null; requestId?: null; body: Markdown; requireApproval?: boolean | null }
    | { kind: 'reply'; anchor?: null; requestId: Ulid; body: Markdown; requireApproval?: null };

export type UpdateDraftInput = {
    body: Markdown;
    /** new_request drafts only. Absent or null keeps the value. */
    requireApproval?: boolean | null;
};

export type SubmitDraftsInput = {
    summary?: string | null;
};

export type ViewedFileInput = {
    path: RepoPath;
    viewed: boolean;
};

export type CreateTokenInput = {
    name: TokenName;
    /** Null: all repositories of the tenant. */
    repositoryId?: Ulid | null;
    /** Null: no expiry. Else from now + 1 minute to now + 366 days. */
    expiresAt?: DateTime | null;
};
