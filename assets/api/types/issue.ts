import type { Actor } from './actor';
import type {
    ApprovalMode,
    CommentLabel,
    CommitSha,
    DateTime,
    IssueApproval,
    IssueCategory,
    IssueEntryKind,
    IssueKey,
    IssueLinkKind,
    IssuePartyKind,
    IssueSection,
    IssueStatus,
    Markdown,
    ProjectKey,
    Question,
    Ulid,
    WaitingFor,
} from './common';

/** The requester or the assignee of an issue (D134). `userId` and `name`, the login, are null for the agent. */
export type IssueParty = {
    kind: IssuePartyKind;
    userId: Ulid | null;
    name: string | null;
};

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
    category: IssueCategory;
    status: IssueStatus;
    approval: IssueApproval;
    /** Null without a claim and after its end. */
    claim: IssueClaim | null;
    requester: IssueParty;
    assignee: IssueParty;
    /** The party with the next step. Null in `backlog`, `done`, and `wont_do`. */
    waitingFor: WaitingFor | null;
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
    /** The newest thread entry after the creation. Null without one (D137). */
    lastActivityAt: DateTime | null;
};

export type IssueSummary = {
    key: IssueKey;
    title: string;
    category: IssueCategory;
    status: IssueStatus;
    approval: IssueApproval;
    claim: IssueClaim | null;
    requester: IssueParty;
    assignee: IssueParty;
    waitingFor: WaitingFor | null;
    updatedAt: DateTime;
    lastActivityAt: DateTime | null;
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
    /** Of a comment with the label `question` or `dismissal`, or with their answers of one with the label `answer` (D124). Else empty. */
    questions: Question[];
    section: IssueSection | null;
    /** Equal to `to` for a hand-over. */
    from: IssueStatus | null;
    to: IssueStatus | null;
    /** `status_changed`: the party after the change. Null on an older entry. */
    waitingFor: WaitingFor | null;
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

/** Why I12 reads no commit of a repository: `no_server_copy` for Pass-through and Browser only, `read_failed` when git failed on the clone, else the clone is not ready. */
export const ISSUE_COMMITS_UNAVAILABLE_REASONS = ['no_server_copy', 'cloning', 'clone_failed', 'removed', 'read_failed'] as const;
export type IssueCommitsUnavailableReason = (typeof ISSUE_COMMITS_UNAVAILABLE_REASONS)[number];

/** The result of I12 (D132): the commits of the branches whose message or branch name has the issue key, read at the call. */
export type IssueCommits = {
    /** At most 100 per repository, newest `committedAt` first. */
    items: {
        /** `Repository.name` */
        repository: string;
        sha: CommitSha;
        /** The first paragraph of the message on one line. Empty for an empty message. */
        subject: string;
        authorName: string;
        committedAt: DateTime;
        onDefaultBranch: boolean;
        /** Null on the default branch. Else a branch that has the commit, a branch with the key in its name first. */
        branch: string | null;
        /** The commit on github.com. Null for a local repository. */
        htmlUrl: string | null;
    }[];
    /** In name order. */
    unavailable: { repository: string; reason: IssueCommitsUnavailableReason }[];
};
