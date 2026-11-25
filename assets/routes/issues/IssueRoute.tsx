import { Banner, Link, RelativeTime, Select } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { type ReactNode, useEffect, useId, useRef, useState, useTransition } from 'react';
import { Link as RouterLink, useFetcher, useLoaderData, useNavigate } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import { COMMENT_LABELS, type CommentLabel, ISSUE_APPROVALS } from '../../api/types/common';
import type { IssueCommentInput } from '../../api/types/inputs';
import type { Issue, IssueDetail, IssueEntry, IssueLink } from '../../api/types/issue';
import { CommentBox, type CommentText } from '../../comments/CommentBox';
import boxStyles from '../../comments/CommentBox.module.css';
import { MarkdownBody } from '../../comments/MarkdownBody';
import { QuestionForm, QuestionList, withDraftAnswers } from '../../comments/Questions';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import { removeSessionDraft, useSessionDraft } from '../../layout/useSessionDraft';
import sidebarStyles from '../../requests/RequestSidebar.module.css';
import { dateTimeFormat } from '../../settings/TokenTable';
import { ActorName, COMMENT_LABEL_TEXT } from './CommentParts';
import { IssueClaimLine } from './IssueClaimLine';
import { IssueStatusLabel } from './IssueStatusLabel';
import { IssueStatusMenu } from './IssueStatusMenu';
import { EntryLink, entryId, IssueTimeline } from './IssueTimeline';
import { IssueTitle } from './IssueTitle';
import styles from './Issues.module.css';
import type { IssueIntent } from './issueData';
import { APPROVAL_MODE_NAMES } from './ApprovalModeField';
import { useIssueWrite } from './useIssueWrite';

/** The server sets `question`, `answer`, and `dismissal` (D124). */
const WRITABLE_LABELS = COMMENT_LABELS.filter((label) => 'question' !== label && 'answer' !== label && 'dismissal' !== label);

/**
 * The layout of the request page: the timeline, the open questions, and the comment box in the main column, the details
 * in the sidebar. The page has no thread widget of its own (D2): Primer parts, `MarkdownBody`, and `CommentBox`.
 */
export function IssueRoute() {
    const { issue, links, thread } = useLoaderData<IssueDetail>();
    useDocumentTitle(`${issue.key} ${issue.title}`);
    const sentFrom = useFocusOnReply(thread);

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
                {null !== issue.claim && <IssueClaimLine claim={issue.claim} />}
            </header>
            <div className={styles.layout}>
                <aside className={`${sidebarStyles.sidebar} ${styles.sidebar}`} aria-label="Issue details" data-testid="issue-sidebar">
                    <SidebarSection title="Project">
                        <Link as={RouterLink} to={`/projects/${issue.project.key}`}>
                            {issue.project.name}
                        </Link>
                        <span className={styles.muted}>{APPROVAL_MODE_NAMES[issue.project.approvalMode]}</span>
                    </SidebarSection>
                    <SidebarSection title="Approval">
                        <ApprovalOfIssue key={issue.key} issue={issue} />
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
                    {thread.map((entry) =>
                        isOpenQuestion(entry) ? (
                            <OpenQuestions key={entry.id} entry={entry} draftKey={answersDraftKey(issue.key, entry)} onSend={sentFrom} />
                        ) : (
                            isWithdrawnQuestion(entry, thread) && <WithdrawnQuestions key={entry.id} entry={entry} draftKey={answersDraftKey(issue.key, entry)} />
                        ),
                    )}
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

/** The mode that binds the issue. In a `by_issue` project the user changes it with I7 (D129). */
function ApprovalOfIssue({ issue }: { issue: Issue }) {
    const { busy, error, send } = useIssueWrite();
    const [chosen, setChosen] = useState(issue.approval);

    if ('by_issue' !== issue.project.approvalMode) {
        return <span data-testid="issue-approval">{APPROVAL_MODE_NAMES[issue.approval]}</span>;
    }

    return (
        <>
            <Select
                aria-label="Approval of the issue"
                value={busy ? chosen : issue.approval}
                disabled={busy}
                onChange={(event) => {
                    const approval = ISSUE_APPROVALS.find((known) => known === event.target.value) ?? issue.approval;
                    setChosen(approval);
                    send({ intent: 'update', input: { approval } });
                }}
                data-testid="issue-approval"
            >
                {ISSUE_APPROVALS.map((approval) => (
                    <Select.Option key={approval} value={approval}>
                        {APPROVAL_MODE_NAMES[approval]}
                    </Select.Option>
                ))}
            </Select>
            {null !== error && (
                <InlineMessage variant="critical" role="alert">
                    {error}
                </InlineMessage>
            )}
        </>
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

/**
 * "Send answers" and "Dismiss" remove their form, so the page goes to the answers or the dismissal of that form, which
 * the timeline scrolls to and focuses. A dismissal of a move to `wont_do` moves nothing.
 *
 * @returns Records the question comment whose form sends
 */
function useFocusOnReply(thread: IssueEntry[]): (questionId: string) => void {
    const navigate = useNavigate();
    const sent = useRef<string | null>(null);

    useEffect(() => {
        const reply = thread.find(({ label, replaces }) => ('answer' === label || 'dismissal' === label) && null !== replaces && replaces === sent.current);
        if (undefined !== reply) {
            sent.current = null;
            void navigate({ hash: entryId(reply.id) }, { replace: true });
        }
    }, [thread, navigate]);

    return (questionId) => {
        sent.current = questionId;
    };
}

/** A question comment is open while it has a question and no comment replaced it (D124). */
function isOpenQuestion({ label, questions, replacedBy }: IssueEntry): boolean {
    return 'question' === label && questions.length > 0 && null === replacedBy;
}

/** The agent replaced a question comment with a comment that is no answer and no dismissal, so the form of the comment is gone. */
function isWithdrawnQuestion({ label, questions, replacedBy }: IssueEntry, thread: IssueEntry[]): boolean {
    return 'question' === label && questions.length > 0 && thread.some(({ id, label: replacing }) => id === replacedBy && 'answer' !== replacing && 'dismissal' !== replacing);
}

function answersDraftKey(issueKey: string, { id }: IssueEntry): string {
    return `issue:${issueKey}:answers:${id}`;
}

/**
 * The form of an open question comment. "Send answers" and "Dismiss" send I3 with `replaces`, so the answers or the
 * dismissal replace the comment, and the form goes with it.
 */
function OpenQuestions({ entry, draftKey, onSend }: { entry: IssueEntry; draftKey: string; onSend(questionId: string): void }) {
    const send = (input: IssueCommentInput, answersKey: string): IssueIntent => {
        onSend(entry.id);

        return { intent: 'comment', input, draftKey: answersKey };
    };

    return (
        <section className={styles.questions} data-testid="issue-questions">
            <p className={styles.questionsLine}>
                <EntryLink id={entry.id}>Questions</EntryLink> of <ActorName actor={entry.actor} /> <RelativeTime datetime={entry.createdAt} />
            </p>
            <QuestionForm
                label={`Questions of ${entry.actor.name}, ${dateTimeFormat.format(new Date(entry.createdAt))}`}
                questions={entry.questions}
                draftKey={draftKey}
                intent={(questions, answersKey) => send({ body: '', replaces: entry.id, questions }, answersKey)}
                dismiss={(answersKey) => send({ body: '', replaces: entry.id, dismiss: true }, answersKey)}
            />
        </section>
    );
}

/** The typed answers of a withdrawn question comment, in the place of its form, until "Discard". */
function WithdrawnQuestions({ entry, draftKey }: { entry: IssueEntry; draftKey: string }) {
    const [draft, , revert] = useSessionDraft(draftKey);
    const questions = withDraftAnswers(entry.questions, draft);
    const discard = (): void => {
        removeSessionDraft(draftKey);
        revert();
    };

    if (null === questions || null === entry.replacedBy) {
        return null;
    }

    return (
        <Banner
            variant="warning"
            title="The agent replaced these questions."
            description={
                <div className={styles.withdrawn}>
                    <EntryLink id={entry.replacedBy}>Newer version</EntryLink>
                    <QuestionList questions={questions} />
                </div>
            }
            primaryAction={<Banner.PrimaryAction onClick={discard}>Discard</Banner.PrimaryAction>}
            data-testid="withdrawn-questions"
        />
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
