import type { AnchorInput } from './anchor';
import type { TokenName } from './api-token';
import type { ApprovalMode, CodeAccess, CommentLabel, CommitSha, DateTime, Delivery, IssueApproval, IssueStatus, Markdown, ProjectKey, PullRequestUrl, RepoPath, RequestType, Tour, Ulid, UnifiedDiff } from './common';
import type { LocalDirectory } from './repository';

export type ConnectRepositoryInput =
    | { source: 'github'; githubRepositoryId: number; codeAccess: CodeAccess; directory?: LocalDirectory | null }
    | { source: 'local'; githubRepositoryId?: number | null; directory: LocalDirectory; codeAccess?: 'clone' | null };

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
    /** Absent or null opens the request without a title. */
    title?: string | null;
    body: Markdown;
    /** "Ask for a plan first". Default false. */
    requireApproval?: boolean | null;
    /** Default change. */
    type?: RequestType | null;
    /** Absent or null: no group. */
    group?: string | null;
};

export type SuggestRequestInput = {
    /** Absent or null suggests a general request. */
    anchor?: AnchorInput | null;
    title: string;
    body: Markdown;
};

export type CommentInput = {
    /** May be empty when `questions` is not. */
    body: Markdown;
    /** Agents only. */
    question?: boolean;
    /** At most 10. An agent asks without answers; a user answers every question. */
    questions?: QuestionInput[];
};

export type QuestionInput = { question: string; detail?: Markdown | null; answer?: string | null };

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

export type CheckEveryPullRequestInput = {
    enabled: boolean;
};

export type CodeAccessInput = {
    codeAccess: CodeAccess;
};

export type PlanRequirementInput = {
    requireApproval: boolean;
};

export type AcceptSuggestionInput = {
    /** "Ask for a plan first". Absent or null keeps the value. */
    requireApproval?: boolean | null;
};

export type DismissSuggestionInput = {
    note?: string | null;
};

export type TitleInput = {
    title: string;
};

export type LinkCommitsInput = {
    commitSha: CommitSha;
    requestIds: Ulid[];
    note?: string | null;
};

export type LinkPullRequestInput = {
    url: PullRequestUrl;
    requestIds: Ulid[];
    note?: string | null;
};

export type UnlinkPullRequestInput = {
    url: PullRequestUrl;
};

export type CreateDraftInput =
    | { kind: 'new_request'; anchor?: AnchorInput | null; requestId?: null; title?: string | null; body: Markdown; requireApproval?: boolean | null; type?: RequestType | null; group?: string | null }
    | { kind: 'reply'; anchor?: null; requestId: Ulid; title?: null; body: Markdown; requireApproval?: null; type?: null; group?: null };

export type UpdateDraftInput = {
    /** Replaced with `body`: absent or null removes it. new_request drafts only. */
    title?: string | null;
    body: Markdown;
    /** new_request drafts only. Absent or null keeps the value. */
    requireApproval?: boolean | null;
    /** new_request drafts only. Absent or null keeps the value. */
    type?: RequestType | null;
    /** Replaced with `body`: absent or null removes it. new_request drafts only. */
    group?: string | null;
    /** Moves a new_request draft (D115). Absent or null keeps the anchor. */
    anchor?: AnchorInput | null;
};

/** A missing key is null. */
export type DeliveryInput = Partial<Delivery>;

export type SubmitDraftsInput = {
    summary?: string | null;
    /** Absent or null keeps the delivery of the review. */
    delivery?: DeliveryInput | null;
    /** 1 to 500 drafts of the caller. Absent or null: every draft. */
    draftIds?: Ulid[] | null;
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

export type PreferencesInput = {
    /** Replaces the list. Absent or null keeps it. */
    completedTours?: Tour[] | null;
};

export type IssueCommentInput = {
    /** May be empty when `questions` is not. */
    body: Markdown;
    /** Absent or null: comment. With `questions`, the server sets `question` or `answer`. */
    label?: CommentLabel | null;
    /** The comment of the issue that this comment replaces. With answers: the open question comment. */
    replaces?: Ulid | null;
    /** At most 10. An agent asks without answers; a user answers every question of the comment of `replaces`. */
    questions?: QuestionInput[];
};

export type ApprovePlanInput = {
    /** Issue.planRevision of the plan that the user read. */
    planRevision: Ulid;
};

export type CreateProjectInput = {
    key: ProjectKey;
    name: string;
    approvalMode: ApprovalMode;
    approvalGuidelines?: string | null;
};

export type UpdateProjectInput = {
    name?: string | null;
    approvalMode?: ApprovalMode | null;
    /** Absent or null keeps them. Whitespace only removes them. */
    approvalGuidelines?: string | null;
    /** The whole list of the repositories of the project. Absent or null keeps it. */
    repositoryIds?: Ulid[] | null;
};

export type CreateIssueInput = {
    title: string;
    problem?: Markdown | null;
    /** Absent or null: draft. */
    status?: Extract<IssueStatus, 'draft' | 'backlog' | 'todo'> | null;
    /** Only in a `by_issue` project. */
    approval?: IssueApproval | null;
};

/** Absent or null keeps a field. At least one field is given. */
export type UpdateIssueInput = {
    title?: string | null;
    problem?: Markdown | null;
    plan?: Markdown | null;
    result?: Markdown | null;
    followUps?: Markdown | null;
    /** Only in a `by_issue` project. */
    approval?: IssueApproval | null;
};

export type IssueStatusInput = {
    status: IssueStatus;
    /** wont_do needs one. */
    note?: Markdown | null;
    reviewer?: string | null;
};
