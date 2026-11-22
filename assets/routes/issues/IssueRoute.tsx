import { ArrowSwitchIcon, CheckIcon, CommentIcon, DotFillIcon, type Icon, PencilIcon } from '@primer/octicons-react';
import { Link, RelativeTime, Select, Timeline } from '@primer/react';
import { type ReactNode, useId, useState, useTransition } from 'react';
import { Link as RouterLink, useFetcher, useLoaderData, useSearchParams } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import { COMMENT_LABELS, type CommentLabel, type IssueSection as Section } from '../../api/types/common';
import type { Issue, IssueDetail, IssueEntry, IssueLink } from '../../api/types/issue';
import { CommentBox, type CommentText } from '../../comments/CommentBox';
import boxStyles from '../../comments/CommentBox.module.css';
import { MarkdownBody } from '../../comments/MarkdownBody';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import sidebarStyles from '../../requests/RequestSidebar.module.css';
import { ActorName, COMMENT_LABEL_TEXT, CommentLabelBadge, CommentLabelFilter, NoCommentWithLabel } from './CommentParts';
import { IssueSection, SECTIONS } from './IssueSection';
import { IssueStatusLabel } from './IssueStatusLabel';
import { IssueStatusMenu } from './IssueStatusMenu';
import { IssueTitle } from './IssueTitle';
import styles from './Issues.module.css';
import { type IssueIntent, labelFilter } from './issueData';
import { APPROVAL_MODE_NAMES } from './ApprovalModeField';

const SECTION_NAMES: Record<Section, string> = { problem: 'problem', plan: 'plan', result: 'result', followUps: 'follow-ups' };

/**
 * The layout of the request page: the sections and the thread in the main column, the details in the sidebar. The
 * page has no thread widget of its own (D2): Primer parts, `MarkdownBody`, and `CommentBox`.
 */
export function IssueRoute() {
    const { issue, links, thread } = useLoaderData<IssueDetail>();
    const filter = labelFilter(useSearchParams()[0]);
    const entries = null === filter ? thread : thread.filter((entry) => filter === entry.label);
    useDocumentTitle(`${issue.key} ${issue.title}`);

    return (
        <article className={styles.page} data-testid="issue-detail" data-issue-key={issue.key}>
            <header className={styles.header}>
                <Link as={RouterLink} to={`/projects/${issue.project.key}`} className={styles.back}>
                    Issues of {issue.project.name}
                </Link>
                <IssueTitle key={issue.key} issue={issue} />
                <div className={styles.state}>
                    <IssueStatusLabel status={issue.status} />
                    <IssueStatusMenu key={issue.key} issue={issue} />
                    <span className={styles.muted}>
                        {issue.createdBy.name} opened this <RelativeTime datetime={issue.createdAt} /> · updated <RelativeTime datetime={issue.updatedAt} />
                    </span>
                </div>
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
                        <IssueSection key={`${issue.key}:${section}`} issue={issue} section={section} title={title} />
                    ))}
                    <section className={styles.thread} aria-labelledby="issue-thread">
                        <div className={styles.threadHeader}>
                            <h2 id="issue-thread" className={styles.threadTitle}>
                                Thread
                            </h2>
                            <CommentLabelFilter />
                        </div>
                        {entries.length > 0 && (
                            <Timeline clipSidebar data-testid="issue-thread">
                                {entries.map((entry) => (
                                    <ThreadEntry key={entry.id} entry={entry} />
                                ))}
                            </Timeline>
                        )}
                        {0 === entries.length && null !== filter && (
                            <p className={styles.muted} data-testid="issue-thread-empty">
                                <NoCommentWithLabel />
                            </p>
                        )}
                        <CommentForm key={issue.key} issueKey={issue.key} />
                    </section>
                </div>
            </div>
        </article>
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
                    <ActorName actor={entry.actor} />
                    <CommentLabelBadge label={entry.label} /> <EntryAction entry={entry} /> <RelativeTime datetime={entry.createdAt} />
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

/** The label returns to `comment` after a write. A refused write keeps it, as the box keeps its text. */
function CommentForm({ issueKey }: { issueKey: string }) {
    const fetcher = useFetcher<ActionResult>();
    const [commenting, startComment] = useTransition();
    const [label, setLabel] = useState<CommentLabel>('comment');
    const [sent, setSent] = useState(false);
    const labelId = useId();
    const draftKey = `issue:${issueKey}:comment`;
    const error = commenting ? null : (fetcher.data?.error ?? null);
    const comment = ({ body }: CommentText): boolean => {
        if (commenting) {
            return false;
        }
        const intent: IssueIntent = { intent: 'comment', input: { body, label }, draftKey };
        setSent(true);
        startComment(async () => {
            await fetcher.submit(intent, { method: 'post', encType: 'application/json' });
        });

        return true;
    };

    if (sent && !commenting) {
        setSent(false);
        if (null === error) {
            setLabel('comment');
        }
    }

    return (
        <CommentBox
            label="Comment"
            submitLabel="Comment"
            draftKey={draftKey}
            onSubmit={comment}
            error={error}
            busy={commenting}
            actions={
                <span className={boxStyles.option}>
                    <label htmlFor={labelId} className={boxStyles.optionLabel}>
                        Label
                    </label>
                    <Select id={labelId} value={label} onChange={(event) => setLabel(COMMENT_LABELS.find((known) => known === event.target.value) ?? 'comment')} disabled={commenting}>
                        {COMMENT_LABELS.map((known) => (
                            <Select.Option key={known} value={known}>
                                {COMMENT_LABEL_TEXT[known]}
                            </Select.Option>
                        ))}
                    </Select>
                </span>
            }
        />
    );
}
