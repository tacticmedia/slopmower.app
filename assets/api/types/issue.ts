import type { Actor } from './actor';
import type {
    ApprovalMode,
    CommentLabel,
    DateTime,
    IssueApproval,
    IssueEntryKind,
    IssueKey,
    IssueLinkKind,
    IssueSection,
    IssueStatus,
    Markdown,
    ProjectKey,
    Question,
    Ulid,
} from './common';

/** The approval of the plan of `Issue.planRevision`. `reviewer` and `reviewNote` are null for an approval of a user. */
export type PlanApproval = {
    approvedBy: Actor;
    approvedAt: DateTime;
    reviewer: string | null;
    reviewNote: Markdown | null;
};

/** A lease of one agent session on the issue (D130). It ends at `until`, with a status change that passes the issue on, or with I11. */
export type IssueClaim = {
    holder: Actor;
    session: string;
    since: DateTime;
    until: DateTime;
};

/** An issue without its links and its thread: the result of every issue write. */
export type Issue = {
    key: IssueKey;
    title: string;
    status: IssueStatus;
    approval: IssueApproval;
    /** Null without a claim and after its end. */
    claim: IssueClaim | null;
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
    approval: IssueApproval;
    claim: IssueClaim | null;
    updatedAt: DateTime;
};

/** `issue` is the other issue. With `blocked_by`, the issue that is read waits for it. */
export type IssueLink = {
    issue: IssueSummary;
    kind: IssueLinkKind;
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
    /** A comment, empty for the answers of a user, or with `history` the text of this version of a section. */
    body: Markdown | null;
    /** Of a comment with the label `question`, or with their answers of one with the label `answer` (D124). Else empty. */
    questions: Question[];
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
    /** Empty for the answers of a user. */
    body: Markdown;
    questions: Question[];
};

/** The result of `get_issue`: links and thread oldest first. Without `history`, the thread has no replaced entry. */
export type IssueDetail = {
    issue: Issue;
    links: IssueLink[];
    thread: IssueEntry[];
};

/** The result of I9 (D126): the open issues of a project that have a dependency or wait for the user. `issue` waits for `blockedBy`. */
export type IssueGraph = {
    issues: IssueSummary[];
    links: { issue: IssueKey; blockedBy: IssueKey }[];
    /** The open issues of the project that are not in `issues`. */
    otherOpenIssues: number;
};

/** The result of I10 and `get_issue_updates` (D128). Without a cursor: `issues`. With one: `entries`, without the entries of the caller. */
export type IssueUpdatesPage = {
    cursor: number;
    hasMore: boolean;
    issues: IssueSummary[];
    entries: { issue: IssueSummary; entry: IssueEntry }[];
};
