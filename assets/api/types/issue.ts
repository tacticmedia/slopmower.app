import type { Actor } from './actor';
import type {
    ApprovalMode,
    CommentLabel,
    DateTime,
    IssueEntryKind,
    IssueKey,
    IssueSection,
    IssueStatus,
    Markdown,
    ProjectKey,
    Ulid,
} from './common';

/** The approval of the plan of `Issue.planRevision`. `reviewer` and `reviewNote` are null for an approval of a user. */
export type PlanApproval = {
    approvedBy: Actor;
    approvedAt: DateTime;
    reviewer: string | null;
    reviewNote: Markdown | null;
};

/** An issue without its links and its thread: the result of every issue write. */
export type Issue = {
    key: IssueKey;
    title: string;
    status: IssueStatus;
    problem: Markdown | null;
    plan: Markdown | null;
    result: Markdown | null;
    followUps: Markdown | null;
    project: { key: ProjectKey; name: string; approvalMode: ApprovalMode };
    /** The ID of the newest `section_changed` entry of the plan. */
    planRevision: Ulid | null;
    /** A plan with new text clears it. */
    planApproval: PlanApproval | null;
    /** The statuses that the caller can set now, with a note and a reviewer where the rules need them. */
    allowedStatuses: IssueStatus[];
    createdBy: Actor;
    createdAt: DateTime;
    updatedAt: DateTime;
};

export type IssueSummary = {
    key: IssueKey;
    title: string;
    status: IssueStatus;
    updatedAt: DateTime;
};

/** A link has no direction. `issue` is the other issue. */
export type IssueLink = {
    issue: IssueSummary;
    note: Markdown;
    createdBy: Actor;
    createdAt: DateTime;
};

/** One entry of the thread. The fields of another kind are null. A new version replaces an entry, and the replaced entry stays. */
export type IssueEntry = {
    id: Ulid;
    kind: IssueEntryKind;
    /** Null for an entry that is no comment. */
    label: CommentLabel | null;
    actor: Actor;
    createdAt: DateTime;
    /** A comment, or with `history` the text of this version of a section. */
    body: Markdown | null;
    section: IssueSection | null;
    from: IssueStatus | null;
    to: IssueStatus | null;
    note: Markdown | null;
    reviewer: string | null;
    reviewNote: Markdown | null;
    planRevision: Ulid | null;
    /** The entry that this entry replaced. */
    replaces: Ulid | null;
    /** The entry that replaced this entry. Null for a current entry. */
    replacedBy: Ulid | null;
};

/** A comment entry with its issue: an item of `list_comments`. */
export type IssueComment = {
    id: Ulid;
    issue: IssueSummary;
    label: CommentLabel;
    actor: Actor;
    createdAt: DateTime;
    body: Markdown;
};

/** The result of `get_issue`: links and thread oldest first. Without `history`, the thread has no replaced entry. */
export type IssueDetail = {
    issue: Issue;
    links: IssueLink[];
    thread: IssueEntry[];
};
