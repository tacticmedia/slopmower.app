import { ArrowSwitchIcon, CheckIcon, CommentIcon, DotFillIcon, type Icon, PencilIcon } from '@primer/octicons-react';
import { Button, Heading, Label, Link, RelativeTime, Timeline } from '@primer/react';
import { type ReactNode, useId, useTransition } from 'react';
import { Link as RouterLink, useFetcher, useLoaderData } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import type { Actor } from '../../api/types/actor';
import type { IssueSection } from '../../api/types/common';
import type { Issue, IssueDetail, IssueEntry, IssueLink } from '../../api/types/issue';
import { CommentBox, type CommentText } from '../../comments/CommentBox';
import { MarkdownBody } from '../../comments/MarkdownBody';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import { useSubmitOnce } from '../../layout/useSubmitOnce';
import sidebarStyles from '../../requests/RequestSidebar.module.css';
import { IssueStatusLabel } from './IssueStatusLabel';
import styles from './Issues.module.css';
import type { IssueIntent } from './issueData';
import { APPROVAL_MODE_NAMES } from './ProjectsRoute';

const SECTIONS: readonly { section: IssueSection; title: string }[] = [
    { section: 'problem', title: 'Problem' },
    { section: 'plan', title: 'Plan' },
    { section: 'result', title: 'Result' },
    { section: 'followUps', title: 'Follow-ups' },
];

const SECTION_NAMES: Record<IssueSection, string> = { problem: 'problem', plan: 'plan', result: 'result', followUps: 'follow-ups' };

/**
 * The layout of the request page: the sections and the thread in the main column, the details in the sidebar. The
 * page has no thread widget of its own (D2): Primer parts, `MarkdownBody`, and `CommentBox`.
 */
export function IssueRoute() {
    const { issue, links, thread } = useLoaderData<IssueDetail>();
    useDocumentTitle(`${issue.key} ${issue.title}`);

    return (
        <article className={styles.page} data-testid="issue-detail" data-issue-key={issue.key}>
            <header className={styles.header}>
                <Link as={RouterLink} to={`/projects/${issue.project.key}`} className={styles.back}>
                    Issues of {issue.project.name}
                </Link>
                <Heading as="h1" className={styles.title}>
                    {issue.title} <span className={styles.key}>{issue.key}</span>
                </Heading>
                <p className={styles.state}>
                    <IssueStatusLabel status={issue.status} />
                    <span className={styles.muted}>
                        {issue.createdBy.name} opened this <RelativeTime datetime={issue.createdAt} /> · updated <RelativeTime datetime={issue.updatedAt} />
                    </span>
                </p>
            </header>
            <div className={styles.layout}>
                <aside className={`${sidebarStyles.sidebar} ${styles.sidebar}`} aria-label="Issue details" data-testid="issue-sidebar">
                    <SidebarSection title="Project">
                        <Link as={RouterLink} to={`/projects/${issue.project.key}`}>
                            {issue.project.name}
                        </Link>
                        <span className={styles.muted}>{APPROVAL_MODE_NAMES[issue.project.approvalMode]}</span>
                    </SidebarSection>
                    <SidebarSection title="Plan approval">
                        <ApprovalState issue={issue} />
                    </SidebarSection>
                    <SidebarSection title="Links">
                        {0 === links.length ? <span className={styles.muted}>None</span> : <LinkList links={links} />}
                    </SidebarSection>
                </aside>
                <div className={styles.main}>
                    {SECTIONS.map(({ section, title }) => (
                        <SectionBox key={section} section={section} title={title} text={issue[section]}>
                            {'plan' === section && canApprove(issue) && <ApprovePlan key={issue.key} planRevision={issue.planRevision} />}
                        </SectionBox>
                    ))}
                    <section className={styles.thread} aria-labelledby="issue-thread">
                        <h2 id="issue-thread" className={styles.threadTitle}>
                            Thread
                        </h2>
                        {thread.length > 0 && (
                            <Timeline clipSidebar data-testid="issue-thread">
                                {thread.map((entry) => (
                                    <ThreadEntry key={entry.id} entry={entry} />
                                ))}
                            </Timeline>
                        )}
                        <CommentForm key={issue.key} issueKey={issue.key} />
                    </section>
                </div>
            </div>
        </article>
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

function SidebarSection({ title, children }: { title: string; children: ReactNode }) {
    const headingId = useId();

    return (
        <section className={sidebarStyles.section} aria-labelledby={headingId}>
            <h2 id={headingId} className={sidebarStyles.heading}>
                {title}
            </h2>
            {children}
        </section>
    );
}

function ApprovalState({ issue }: { issue: Issue }) {
    const approval = issue.planApproval;

    if (null === issue.planRevision) {
        return <span className={styles.muted}>No plan yet</span>;
    }
    if (null === approval) {
        return <span data-testid="approval-state">Not approved</span>;
    }

    return (
        <span data-testid="approval-state">
            Approved by <ActorName actor={approval.approvedBy} /> <RelativeTime datetime={approval.approvedAt} />
            {null !== approval.reviewer && <span className={styles.muted}> · reviewed by {approval.reviewer}</span>}
        </span>
    );
}

function LinkList({ links }: { links: IssueLink[] }) {
    return (
        <ul className={sidebarStyles.list} aria-label="Linked issues" data-testid="issue-links">
            {links.map(({ issue, note }) => (
                <li key={issue.key} className={styles.link}>
                    <span className={styles.linkTitle}>
                        <Link as={RouterLink} to={`/issues/${issue.key}`}>
                            {issue.key} {issue.title}
                        </Link>
                        <IssueStatusLabel status={issue.status} />
                    </span>
                    <MarkdownBody markdown={note} />
                </li>
            ))}
        </ul>
    );
}

type SectionBoxProps = {
    section: IssueSection;
    title: string;
    text: string | null;
    /** The footer of the box. */
    children?: ReactNode;
};

function SectionBox({ section, title, text, children }: SectionBoxProps) {
    const headingId = useId();

    return (
        <section className={styles.section} aria-labelledby={headingId} data-testid="issue-section" data-section={section}>
            <h2 id={headingId} className={styles.sectionTitle}>
                {title}
            </h2>
            <div className={styles.sectionBody}>{null === text || '' === text.trim() ? <span className={styles.muted}>Not written yet.</span> : <MarkdownBody markdown={text} />}</div>
            {children}
        </section>
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

const ENTRY_ICONS: Record<string, Icon> = {
    comment: CommentIcon,
    status_changed: ArrowSwitchIcon,
    section_changed: PencilIcon,
    plan_approved: CheckIcon,
};

/** An entry of an unknown kind shows its kind as text, with its actor and time. */
function ThreadEntry({ entry }: { entry: IssueEntry }) {
    const EntryIcon = ENTRY_ICONS[entry.kind] ?? DotFillIcon;
    const text = entry.body ?? entry.note ?? entry.reviewNote;

    return (
        <Timeline.Item data-testid="issue-entry" data-kind={entry.kind}>
            <Timeline.Badge variant={'plan_approved' === entry.kind ? 'success' : undefined}>
                <EntryIcon aria-hidden />
            </Timeline.Badge>
            <Timeline.Body className={styles.entry}>
                <p className={styles.entryLine}>
                    <ActorName actor={entry.actor} /> <EntryAction entry={entry} /> <RelativeTime datetime={entry.createdAt} />
                </p>
                {null !== text && (
                    <div className={styles.entryText}>
                        <MarkdownBody markdown={text} />
                    </div>
                )}
            </Timeline.Body>
        </Timeline.Item>
    );
}

function EntryAction({ entry }: { entry: IssueEntry }) {
    const reviewedBy = null === entry.reviewer ? '' : `, reviewed by ${entry.reviewer},`;

    if ('status_changed' === entry.kind && null !== entry.from && null !== entry.to) {
        return (
            <>
                changed the status from <IssueStatusLabel status={entry.from} /> to <IssueStatusLabel status={entry.to} />
                {reviewedBy}
            </>
        );
    }
    if ('section_changed' === entry.kind && null !== entry.section) {
        return `changed the ${SECTION_NAMES[entry.section]}`;
    }
    if ('plan_approved' === entry.kind) {
        return `approved the plan${reviewedBy}`;
    }

    return 'comment' === entry.kind ? 'commented' : entry.kind;
}

function ActorName({ actor }: { actor: Actor }) {
    return (
        <>
            <strong>{actor.name}</strong>
            {'agent' === actor.kind && (
                <>
                    {' '}
                    <Label variant="secondary">Agent</Label>
                </>
            )}
        </>
    );
}

function CommentForm({ issueKey }: { issueKey: string }) {
    const fetcher = useFetcher<ActionResult>();
    const [commenting, startComment] = useTransition();
    const draftKey = `issue:${issueKey}:comment`;
    const comment = ({ body }: CommentText): boolean => {
        if (commenting) {
            return false;
        }
        const intent: IssueIntent = { intent: 'comment', input: { body }, draftKey };
        startComment(async () => {
            await fetcher.submit(intent, { method: 'post', encType: 'application/json' });
        });

        return true;
    };

    return <CommentBox label="Comment" submitLabel="Comment" draftKey={draftKey} onSubmit={comment} error={commenting ? null : (fetcher.data?.error ?? null)} busy={commenting} />;
}
