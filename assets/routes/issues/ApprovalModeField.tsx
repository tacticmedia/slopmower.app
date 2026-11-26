import { FormControl, Radio, RadioGroup } from '@primer/react';
import { APPROVAL_MODES, type ApprovalMode } from '../../api/types/common';

export const APPROVAL_MODE_NAMES: Record<ApprovalMode, string> = {
    user: 'User approval',
    agent_review: 'Agent review',
    self: 'No approval',
    by_issue: 'Per issue',
};

/** Who approves a plan and who ends an issue (D117, D129). */
export const APPROVAL_MODE_SUMMARIES: Record<ApprovalMode, string> = {
    user: 'You approve the plan and accept the work. An agent cannot end the issue without you.',
    agent_review: 'A second agent reviews the plan and the work. Then the agent approves the plan and ends the issue, with the review on record.',
    self: 'The agent starts without a plan approval. It checks its own work and ends the issue.',
    by_issue: 'Each issue has one of these three. An agent chooses it by your guidelines when it creates the issue, and you change it on the issue.',
};

/** The form field `approvalMode` of a project. */
export function ApprovalModeField({ value, onChange }: { value: ApprovalMode; onChange?: (mode: ApprovalMode) => void }) {
    return (
        <RadioGroup name="approvalMode" onChange={(selected) => onChange?.(APPROVAL_MODES.find((mode) => mode === selected) ?? value)}>
            <RadioGroup.Label>Approval mode</RadioGroup.Label>
            <RadioGroup.Caption>What an agent does without you. You approve a plan and end an issue in every mode.</RadioGroup.Caption>
            {APPROVAL_MODES.map((mode) => (
                <FormControl key={mode}>
                    <Radio value={mode} defaultChecked={mode === value} />
                    <FormControl.Label>{APPROVAL_MODE_NAMES[mode]}</FormControl.Label>
                    <FormControl.Caption>{APPROVAL_MODE_SUMMARIES[mode]}</FormControl.Caption>
                </FormControl>
            ))}
        </RadioGroup>
    );
}
