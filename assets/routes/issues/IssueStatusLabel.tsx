import { Label, type LabelProps } from '@primer/react';
import type { IssueApproval, IssueCategory, IssueStatus } from '../../api/types/common';
import { APPROVAL_MODE_NAMES } from './ApprovalModeField';

export const ISSUE_STATUS_TEXT: Record<IssueStatus, string> = {
    draft: 'Draft',
    backlog: 'Backlog',
    todo: 'To do',
    in_progress: 'In progress',
    in_review: 'In review',
    approved: 'Approved',
    done: 'Done',
    wont_do: "Won't do",
};

const VARIANTS: Record<IssueStatus, NonNullable<LabelProps['variant']>> = {
    draft: 'secondary',
    backlog: 'secondary',
    todo: 'accent',
    in_progress: 'attention',
    in_review: 'severe',
    approved: 'success',
    done: 'done',
    wont_do: 'secondary',
};

/** The names of the categories on the screens (D133). */
export const ISSUE_CATEGORY_TEXT: Record<IssueCategory, string> = { bug: 'Bug report', change: 'Change request' };

/** A change shows no label. */
export function BugLabel({ category }: { category: IssueCategory }) {
    return 'bug' === category ? (
        <Label variant="danger" data-testid="bug-label">
            {ISSUE_CATEGORY_TEXT.bug}
        </Label>
    ) : null;
}

/** An issue that an agent works on and ends without the user (D129). */
export function NoApprovalLabel({ approval }: { approval: IssueApproval }) {
    return 'self' === approval ? (
        <Label variant="secondary" data-testid="no-approval">
            {APPROVAL_MODE_NAMES.self}
        </Label>
    ) : null;
}

export function IssueStatusLabel({ status }: { status: IssueStatus }) {
    return (
        <Label variant={VARIANTS[status]} data-testid="issue-status">
            {ISSUE_STATUS_TEXT[status]}
        </Label>
    );
}
