import { FormControl, Radio, RadioGroup } from '@primer/react';
import { APPROVAL_MODES, type ApprovalMode } from '../../api/types/common';

export const APPROVAL_MODE_NAMES: Record<ApprovalMode, string> = {
    user: 'User approval',
    agent_review: 'Agent review',
    self: 'No approval',
};

/** Who approves a plan and who ends an issue (D117). */
const SUMMARIES: Record<ApprovalMode, string> = {
    user: 'You approve each plan, and you end each issue.',
    agent_review: 'An agent approves a plan and ends an issue, each with the review of a second agent on record.',
    self: 'An agent starts without an approval and ends its issues.',
};

/** The form field `approvalMode` of a project. */
export function ApprovalModeField({ value }: { value: ApprovalMode }) {
    return (
        <RadioGroup name="approvalMode">
            <RadioGroup.Label>Approval mode</RadioGroup.Label>
            <RadioGroup.Caption>What an agent does without you. You approve a plan and end an issue in every mode.</RadioGroup.Caption>
            {APPROVAL_MODES.map((mode) => (
                <FormControl key={mode}>
                    <Radio value={mode} defaultChecked={mode === value} />
                    <FormControl.Label>{APPROVAL_MODE_NAMES[mode]}</FormControl.Label>
                    <FormControl.Caption>{SUMMARIES[mode]}</FormControl.Caption>
                </FormControl>
            ))}
        </RadioGroup>
    );
}
