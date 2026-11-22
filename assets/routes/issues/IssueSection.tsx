import { Button } from '@primer/react';
import { useId } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import type { IssueSection as Section } from '../../api/types/common';
import type { Issue } from '../../api/types/issue';
import { CommentBox } from '../../comments/CommentBox';
import { MarkdownBody } from '../../comments/MarkdownBody';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import { readSessionDraft } from '../../layout/useSessionDraft';
import { useSubmitOnce } from '../../layout/useSubmitOnce';
import styles from './Issues.module.css';
import type { IssueIntent } from './issueData';
import { useIssueWrite } from './useIssueWrite';

export const SECTIONS: readonly { section: Section; title: string }[] = [
    { section: 'problem', title: 'Problem' },
    { section: 'plan', title: 'Plan' },
    { section: 'result', title: 'Result' },
    { section: 'followUps', title: 'Follow-ups' },
];

/** What a section holds: the placeholder of its editor. */
export const SECTION_CONTENT: Record<Section, string> = {
    problem: 'What is wanted, and how to tell that it is done',
    plan: 'The steps, the files, and the tests',
    result: 'What was done, with the commits, and each deviation from the plan',
    followUps: 'What came up and was left out',
};

/**
 * A section as Markdown. "Edit" opens the comment box in its place: I7 with the text, a session draft per issue and
 * section, and the leave guard. The editor closes when the write succeeds, and an edit that a reload kept opens it again.
 */
export function IssueSection({ issue, section, title }: { issue: Issue; section: Section; title: string }) {
    const headingId = useId();
    const draftKey = `issue:${issue.key}:${section}`;
    const { busy, error, open: editing, setOpen, send } = useIssueWrite(() => null !== readSessionDraft(draftKey));
    const text = issue[section];

    return (
        <section className={styles.section} aria-labelledby={headingId} data-testid="issue-section" data-section={section}>
            <div className={styles.sectionHeader}>
                <h2 id={headingId} className={styles.sectionTitle}>
                    {title}
                </h2>
                {!editing && (
                    <Button size="small" variant="invisible" aria-label={`Edit ${title}`} onClick={() => setOpen(true)}>
                        Edit
                    </Button>
                )}
            </div>
            {editing ? (
                <div className={styles.sectionEditor}>
                    <CommentBox
                        label={title}
                        placeholder={SECTION_CONTENT[section]}
                        submitLabel="Save"
                        draftKey={draftKey}
                        onSubmit={({ body }) => send({ intent: 'update', input: { [section]: body }, draftKey })}
                        onCancel={() => setOpen(false)}
                        initialBody={text ?? ''}
                        initialTextSaved
                        error={error}
                        busy={busy}
                        focusOnMount
                    />
                </div>
            ) : (
                <div className={styles.sectionBody}>{null === text || '' === text.trim() ? <span className={styles.muted}>Not written yet.</span> : <MarkdownBody markdown={text} />}</div>
            )}
            {'plan' === section && canApprove(issue) && <ApprovePlan planRevision={issue.planRevision} />}
        </section>
    );
}

/** A user approves the plan of the page while the issue is open. An approval of an agent does not count in the mode `user`. */
function canApprove(issue: Issue): issue is Issue & { planRevision: string } {
    return (
        null !== issue.planRevision &&
        '' !== (issue.plan ?? '').trim() &&
        'done' !== issue.status &&
        'wont_do' !== issue.status &&
        'user' !== issue.planApproval?.approvedBy.kind
    );
}

/** I4 for the plan revision that the page shows. A refusal stays visible while the loader shows the newest plan, so only another issue remounts the form. */
function ApprovePlan({ planRevision }: { planRevision: string }) {
    const fetcher = useFetcher<ActionResult>();
    const intent: IssueIntent = { intent: 'approve_plan', input: { planRevision } };
    const submitOnce = useSubmitOnce(() => fetcher.submit(intent, { method: 'post', encType: 'application/json' }));

    return (
        <fetcher.Form method="post" onSubmit={submitOnce} className={styles.sectionFooter} data-testid="approve-plan">
            <ActionErrorMessage result={fetcher.data} />
            <Button type="submit" variant="primary" loading={'idle' !== fetcher.state}>
                Approve plan
            </Button>
        </fetcher.Form>
    );
}
