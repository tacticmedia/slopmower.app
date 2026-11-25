import { Button } from '@primer/react';
import type { Ref } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import type { IssueCategory, IssueSection as Section } from '../../api/types/common';
import type { Issue } from '../../api/types/issue';
import { CommentBox } from '../../comments/CommentBox';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import { readSessionDraft } from '../../layout/useSessionDraft';
import { useSubmitOnce } from '../../layout/useSubmitOnce';
import styles from './Issues.module.css';
import type { IssueIntent } from './issueData';
import { type IssueWrite, useIssueWrite } from './useIssueWrite';

export const SECTION_TITLES: Record<Section, string> = { problem: 'Problem', plan: 'Plan', result: 'Result', followUps: 'Follow-ups' };

/** What a section holds: the placeholder of its editor. The problem of an issue holds what its category says. */
export const SECTION_CONTENT: Record<Section, string> = {
    problem: 'What is wrong or wanted, and how to tell that it is done',
    plan: 'The steps, the files, and the tests',
    result: 'What was done, with the commits, and each deviation from the plan',
    followUps: 'What came up and was left out',
};

/** What the problem holds for each category (D133). */
export const PROBLEM_CONTENT: Record<IssueCategory, string> = {
    bug: 'What is wrong, how to see it, and what is expected',
    change: 'What is wanted, and how to tell that it is done',
};

/** Whitespace only is no text, as the rules of the server read a section. */
export function hasText(issue: Issue, section: Section): boolean {
    return '' !== (issue[section] ?? '').trim();
}

function draftKey(issue: Issue, section: Section): string {
    return `issue:${issue.key}:${section}`;
}

/** The write of one section. Its editor is open at once for an edit that a reload kept. */
export function useSectionWrite(issue: Issue, section: Section): IssueWrite {
    return useIssueWrite(() => null !== readSessionDraft(draftKey(issue, section)));
}

/**
 * The comment box with the text that the section has: a session draft per issue and section, and the leave guard.
 * "Save" sends I7, which appends the new version to the timeline. The editor closes when the write succeeds.
 */
export function SectionEditor({ issue, section, write }: { issue: Issue; section: Section; write: IssueWrite }) {
    const key = draftKey(issue, section);

    return (
        <CommentBox
            label={SECTION_TITLES[section]}
            labelVisible
            placeholder={'problem' === section ? PROBLEM_CONTENT[issue.category] : SECTION_CONTENT[section]}
            submitLabel="Save"
            draftKey={key}
            onSubmit={({ body }) => write.send({ intent: 'update', input: { [section]: body }, draftKey: key })}
            onCancel={() => write.setOpen(false)}
            initialBody={issue[section] ?? ''}
            initialTextSaved
            error={write.error}
            busy={write.busy}
            focusOnMount
        />
    );
}

/** A user approves the plan of the page while the issue is open. An approval of an agent does not count in the mode `user`. */
export function canApprove(issue: Issue): issue is Issue & { planRevision: string } {
    return null !== issue.planRevision && hasText(issue, 'plan') && 'done' !== issue.status && 'wont_do' !== issue.status && 'user' !== issue.planApproval?.approvedBy.kind;
}

/**
 * I4 for the plan revision that the page shows. A refusal stays visible while the loader shows the newest plan, so only
 * another issue remounts the form.
 *
 * @param disabled The plan changed while the page was open, and the user has not read it yet
 */
export function ApprovePlan({ planRevision, disabled, ref }: { planRevision: string; disabled: boolean; ref: Ref<HTMLButtonElement> }) {
    const fetcher = useFetcher<ActionResult>();
    const intent: IssueIntent = { intent: 'approve_plan', input: { planRevision } };
    const submitOnce = useSubmitOnce(() => fetcher.submit(intent, { method: 'post', encType: 'application/json' }));

    return (
        <fetcher.Form method="post" onSubmit={submitOnce} className={styles.sectionFooter} data-testid="approve-plan">
            <ActionErrorMessage result={fetcher.data} />
            <Button ref={ref} type="submit" variant="primary" loading={'idle' !== fetcher.state} disabled={disabled}>
                Approve plan
            </Button>
        </fetcher.Form>
    );
}
