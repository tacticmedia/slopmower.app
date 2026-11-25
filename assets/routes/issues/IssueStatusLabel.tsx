import { Label, type LabelProps } from '@primer/react';
import type { IssueApproval, IssueStatus } from '../../api/types/common';
import { APPROVAL_MODE_NAMES } from './ApprovalModeField';

export const ISSUE_STATUS_TEXT: Record<IssueStatus, string> = {
    draft: 'Draft',
    backlog: 'Backlog',
    todo: 'To do',
    in_progress: 'In progress',
    awaiting_user_input: 'Waiting for you',
    in_review: 'In review',
    done: 'Done',
    wont_do: "Won't do",
};

const VARIANTS: Record<IssueStatus, NonNullable<LabelProps['variant']>> = {
    draft: 'secondary',
    backlog: 'secondary',
    todo: 'accent',
    in_progress: 'attention',
    awaiting_user_input: 'severe',
    in_review: 'success',
    done: 'done',
    wont_do: 'secondary',
};

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
