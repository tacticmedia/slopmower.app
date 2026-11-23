import { Link, RelativeTime, Select } from '@primer/react';
import { type ReactNode, useEffect, useId, useRef, useState, useTransition } from 'react';
import { Link as RouterLink, useFetcher, useLoaderData, useNavigate } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import { COMMENT_LABELS, type CommentLabel } from '../../api/types/common';
import type { Issue, IssueDetail, IssueEntry, IssueLink } from '../../api/types/issue';
import { CommentBox, type CommentText } from '../../comments/CommentBox';
import boxStyles from '../../comments/CommentBox.module.css';
import { MarkdownBody } from '../../comments/MarkdownBody';
import { QuestionForm } from '../../comments/Questions';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import sidebarStyles from '../../requests/RequestSidebar.module.css';
import { dateTimeFormat } from '../../settings/TokenTable';
import { ActorName, COMMENT_LABEL_TEXT } from './CommentParts';
import { IssueStatusLabel } from './IssueStatusLabel';
import { IssueStatusMenu } from './IssueStatusMenu';
import { EntryLink, entryId, IssueTimeline } from './IssueTimeline';
import { IssueTitle } from './IssueTitle';
import styles from './Issues.module.css';
import type { IssueIntent } from './issueData';
import { APPROVAL_MODE_NAMES } from './ApprovalModeField';

/** The server sets `question` and `answer` (D124). */
const WRITABLE_LABELS = COMMENT_LABELS.filter((label) => 'question' !== label && 'answer' !== label);

/**
 * The layout of the request page: the timeline, the open questions, and the comment box in the main column, the details
 * in the sidebar. The page has no thread widget of its own (D2): Primer parts, `MarkdownBody`, and `CommentBox`.
 */
export function IssueRoute() {
    const { issue, links, thread } = useLoaderData<IssueDetail>();
    useDocumentTitle(`${issue.key} ${issue.title}`);
    useFocusOnAnswers(thread);

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
                <div key={issue.key} className={styles.main}>
                    <IssueTimeline issue={issue} thread={thread} />
                    {thread.filter(isOpenQuestion).map((entry) => (
                        <OpenQuestions key={entry.id} entry={entry} />
                    ))}
                    <CommentForm issueKey={issue.key} />
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

/** "Send answers" removes its form, so the page goes to the answer comment, which the timeline scrolls to and focuses. */
function useFocusOnAnswers(thread: IssueEntry[]): void {
    const navigate = useNavigate();
    const open = useRef<string[]>([]);

    useEffect(() => {
        const answer = thread.find(({ label, replaces }) => 'answer' === label && null !== replaces && open.current.includes(replaces));
        open.current = thread.filter(isOpenQuestion).map(({ id }) => id);
        if (undefined !== answer) {
            void navigate({ hash: entryId(answer.id) }, { replace: true });
        }
    }, [thread, navigate]);
}

/** A question comment is open while it has a question and no comment replaced it (D124). */
function isOpenQuestion({ label, questions, replacedBy }: IssueEntry): boolean {
    return 'question' === label && questions.length > 0 && null === replacedBy;
}

/**
 * The form of an open question comment. "Send answers" sends I3 with `replaces`, so the answers replace the comment,
 * and the form goes with it.
 */
function OpenQuestions({ entry }: { entry: IssueEntry }) {
    return (
        <section className={styles.questions} data-testid="issue-questions">
            <p className={styles.questionsLine}>
                <EntryLink id={entry.id}>Questions</EntryLink> of <ActorName actor={entry.actor} /> <RelativeTime datetime={entry.createdAt} />
            </p>
            <QuestionForm
                label={`Questions of ${entry.actor.name}, ${dateTimeFormat.format(new Date(entry.createdAt))}`}
                questions={entry.questions}
                intent={(questions): IssueIntent => ({ intent: 'comment', input: { body: '', replaces: entry.id, questions } })}
            />
        </section>
    );
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
                    <Select id={labelId} value={label} onChange={(event) => setLabel(WRITABLE_LABELS.find((known) => known === event.target.value) ?? 'comment')} disabled={commenting}>
                        {WRITABLE_LABELS.map((known) => (
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
