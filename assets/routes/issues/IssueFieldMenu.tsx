import { ActionList, ActionMenu } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { useState } from 'react';
import { useRouteLoaderData } from 'react-router';
import { ISSUE_APPROVALS, ISSUE_CATEGORIES, ISSUE_PARTY_KINDS, type IssuePartyKind, type WaitingFor } from '../../api/types/common';
import type { UpdateIssueInput } from '../../api/types/inputs';
import type { Issue } from '../../api/types/issue';
import type { Me } from '../../api/types/me';
import { APPROVAL_MODE_NAMES, APPROVAL_MODE_SUMMARIES } from './ApprovalModeField';
import { ISSUE_CATEGORY_MEANINGS, ISSUE_CATEGORY_TEXT } from './IssueStatusLabel';
import styles from './Issues.module.css';
import { useIssueWrite } from './useIssueWrite';

/** Who has the next step (D134). */
const PARTY_MEANINGS: Record<WaitingFor, Record<IssuePartyKind, string>> = {
    requester: {
        user: 'You ask for the work and accept it. The issue waits for you in Draft and In review.',
        agent: 'An agent asks for the work and accepts it. The server refuses this with User approval.',
    },
    assignee: {
        user: 'You do the work. The issue waits for you in To do, In progress, and Approved.',
        agent: 'An agent does the work. The issue waits for the agent in To do, In progress, and Approved.',
    },
};

type FieldMenuProps<T extends string> = {
    field: string;
    value: T;
    options: readonly T[];
    text: Record<T, string>;
    description: Record<T, string>;
    input(option: T): UpdateIssueInput;
    testId: string;
};

/** I7 with the chosen option. The current option sends nothing. The button shows the chosen option while the write runs. */
function FieldMenu<T extends string>({ field, value, options, text, description, input, testId }: FieldMenuProps<T>) {
    const { busy, error, send } = useIssueWrite();
    const [chosen, setChosen] = useState(value);
    const shown = busy ? chosen : value;
    const choose = (option: T): void => {
        if (option !== value && send({ intent: 'update', input: input(option) })) {
            setChosen(option);
        }
    };

    return (
        <>
            <ActionMenu>
                <ActionMenu.Button size="small" loading={busy} aria-label={`${field}: ${text[shown]}`} data-testid={testId}>
                    {text[shown]}
                </ActionMenu.Button>
                <ActionMenu.Overlay width="medium">
                    <ActionList selectionVariant="single">
                        {options.map((option) => (
                            <ActionList.Item key={option} selected={option === shown} onSelect={() => choose(option)}>
                                {text[option]}
                                <ActionList.Description variant="block">{description[option]}</ActionList.Description>
                            </ActionList.Item>
                        ))}
                    </ActionList>
                </ActionMenu.Overlay>
            </ActionMenu>
            {null !== error && (
                <InlineMessage variant="critical" role="alert">
                    {error}
                </InlineMessage>
            )}
        </>
    );
}

/** I7 with `category` (D133). */
export function CategoryOfIssue({ issue }: { issue: Issue }) {
    return (
        <FieldMenu
            field="Category"
            value={issue.category}
            options={ISSUE_CATEGORIES}
            text={ISSUE_CATEGORY_TEXT}
            description={ISSUE_CATEGORY_MEANINGS}
            input={(category) => ({ category })}
            testId="issue-category"
        />
    );
}

/** I7 with `requester` or `assignee`: the signed-in user or "Agent" (D134). */
export function PartyOfIssue({ issue, role }: { issue: Issue; role: WaitingFor }) {
    const login = useRouteLoaderData<Me>('root')?.user.login ?? 'You';

    return (
        <FieldMenu
            field={'requester' === role ? 'Requester' : 'Assignee'}
            value={issue[role].kind}
            options={ISSUE_PARTY_KINDS}
            text={{ user: login, agent: 'Agent' }}
            description={PARTY_MEANINGS[role]}
            input={(kind) => ({ [role]: kind })}
            testId={`issue-${role}`}
        />
    );
}

/** The mode that binds the issue, with what it does. In a `by_issue` project the user changes it with I7 (D129). */
export function ApprovalOfIssue({ issue }: { issue: Issue }) {
    return (
        <>
            {'by_issue' === issue.project.approvalMode ? (
                <FieldMenu
                    field="Approval"
                    value={issue.approval}
                    options={ISSUE_APPROVALS}
                    text={APPROVAL_MODE_NAMES}
                    description={APPROVAL_MODE_SUMMARIES}
                    input={(approval) => ({ approval })}
                    testId="issue-approval"
                />
            ) : (
                <span data-testid="issue-approval">{APPROVAL_MODE_NAMES[issue.approval]}</span>
            )}
            <span className={styles.muted} data-testid="issue-approval-summary">
                {APPROVAL_MODE_SUMMARIES[issue.approval]}
            </span>
        </>
    );
}
