import { Label, type LabelProps } from '@primer/react';
import type { IssueStatus } from '../../api/types/common';

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

export function IssueStatusLabel({ status }: { status: IssueStatus }) {
    return (
        <Label variant={VARIANTS[status]} data-testid="issue-status">
            {ISSUE_STATUS_TEXT[status]}
        </Label>
    );
}
