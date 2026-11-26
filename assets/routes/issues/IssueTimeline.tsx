import { ArrowSwitchIcon, CheckIcon, ChevronRightIcon, CommentIcon, DotFillIcon, type Icon, PencilIcon } from '@primer/octicons-react';
import { Button, Details, Label, Link, RelativeTime, Timeline } from '@primer/react';
import { type ReactNode, useEffect } from 'react';
import { Link as RouterLink, useLocation } from 'react-router';
import { type CommentLabel, ISSUE_SECTIONS, type IssueSection as Section } from '../../api/types/common';
import type { Issue, IssueEntry } from '../../api/types/issue';
import { MarkdownBody } from '../../comments/MarkdownBody';
import { QuestionList } from '../../comments/Questions';
import { ActorName, CommentLabelBadge, CommentLabelFilter, NoCommentWithLabel } from './CommentParts';
import { hasText, planChangeRequest, SECTION_TITLES, SectionEditor, useSectionWrite } from './IssueSection';
import { IssueStatusLabel } from './IssueStatusLabel';
import styles from './Issues.module.css';
import { labelFilter } from './issueData';
import { SectionEntry } from './SectionEntry';
import { partyName } from './WaitingLabel';

const ENTRY_ICONS: Record<string, Icon> = {
    comment: CommentIcon,
    status_changed: ArrowSwitchIcon,
    section_changed: PencilIcon,
    plan_approved: CheckIcon,
};

type SectionVersion = IssueEntry & { section: Section };

/** A question comment that the answers or a dismissal of a user replaced (D124): its state, and the link to that comment. */
type ClosedQuestion = { state: string; link: string };

const CLOSED_QUESTIONS: Partial<Record<CommentLabel, ClosedQuestion>> = {
    answer: { state: 'Answered', link: 'Answers' },
    dismissal: { state: 'Dismissed', link: 'Dismissal' },
};

/** The entry of the text that a section has now. A section without text has none: "Write" gives it one. */
function isCurrentVersion(issue: Issue, entry: IssueEntry): entry is SectionVersion {
    return 'section_changed' === entry.kind && null !== entry.section && null === entry.replacedBy && hasText(issue, entry.section);
}

/**
 * The thread of I2 with `history`: nothing changes in place (D123). A section version, a comment, a status change, and
 * a plan approval are entries, oldest first. A replaced entry and the current notes stay as one closed line.
 */
export function IssueTimeline({ issue, thread }: { issue: Issue; thread: IssueEntry[] }) {
    const { search, hash, key } = useLocation();
    const filter = labelFilter(new URLSearchParams(search));
    const entries = null === filter ? thread : thread.filter((entry) => filter === entry.label);
    const closed = new Map(thread.flatMap(({ label, replaces }) => (null === label || undefined === CLOSED_QUESTIONS[label] ? [] : [[replaces, CLOSED_QUESTIONS[label]] as const])));
    const changesRequest = planChangeRequest(issue, thread);
    const earlierVersions = new Map(thread.flatMap((entry) => (null === entry.section || null === entry.replacedBy ? [] : [[entry.replacedBy, entry] as const])));

    // The browser does not move to the hash of a navigation of the router. `key` moves again for the same link.
    useEffect(() => {
        const entry = document.getElementById(hash.slice(1));
        entry?.querySelector('details')?.setAttribute('open', '');
        entry?.scrollIntoView();
        entry?.focus({ preventScroll: true });
    }, [hash, key]);

    return (
        <section className={styles.thread} aria-labelledby="issue-timeline">
            <div className={styles.threadHeader}>
                <h2 id="issue-timeline" className={styles.threadTitle}>
                    Timeline
                </h2>
                <CommentLabelFilter />
            </div>
            <div className={styles.current} role="group" aria-label="Current sections" data-testid="issue-current">
                <span className={styles.muted}>Current</span>
                {ISSUE_SECTIONS.map((section) => {
                    const version = thread.find((entry) => section === entry.section && isCurrentVersion(issue, entry));

                    if (undefined !== version) {
                        return (
                            <EntryLink key={section} id={version.id}>
                                {SECTION_TITLES[section]}
                            </EntryLink>
                        );
                    }

                    // The notes are the working memory of the agents, so the page offers no "Write" (D143).
                    return 'notes' === section ? null : <WriteSection key={section} issue={issue} section={section} />;
                })}
            </div>
            {entries.length > 0 && (
                <Timeline clipSidebar data-testid="issue-thread">
                    {entries.map((entry) => (
                        <TimelineEntry
                            key={isCurrentVersion(issue, entry) ? `section:${entry.section}` : entry.id}
                            issue={issue}
                            entry={entry}
                            earlier={earlierVersions.get(entry.id)}
                            linked={hash === `#${entryId(entry.id)}`}
                            closed={closed.get(entry.id) ?? null}
                            changesRequest={changesRequest}
                        />
                    ))}
                </Timeline>
            )}
            {0 === entries.length && null !== filter && (
                <p className={styles.muted} data-testid="issue-thread-empty">
                    <NoCommentWithLabel />
                </p>
            )}
        </section>
    );
}

export function entryId(id: string): string {
    return `entry-${id}`;
}

/** To an entry of this page, without the label filter, which can hide the entry. */
export function EntryLink({ id, children }: { id: string; children: ReactNode }) {
    return (
        <Link as={RouterLink} to={{ hash: entryId(id) }}>
            {children}
        </Link>
    );
}

/** A section without text, in the row "Current". Its editor takes the line below the row. */
function WriteSection({ issue, section }: { issue: Issue; section: Section }) {
    const write = useSectionWrite(issue, section);
    const title = SECTION_TITLES[section];

    if (write.open) {
        return (
            <div className={styles.currentEditor}>
                <SectionEditor issue={issue} section={section} write={write} />
            </div>
        );
    }

    return (
        <span className={styles.currentItem}>
            <span className={styles.muted}>{title}</span>
            <Button size="small" aria-label={`Write ${title}`} onClick={() => write.setOpen(true)}>
                Write
            </Button>
        </span>
    );
}

/**
 * An entry of an unknown kind shows its kind as text, with its actor and time.
 *
 * @param earlier        The section version that this entry replaced
 * @param linked         The hash of the URL names the entry
 * @param closed         The entry is a question comment that the answers or a dismissal replaced
 * @param changesRequest The request for changes of the current plan revision
 */
function TimelineEntry({ issue, entry, earlier, linked, closed, changesRequest }: { issue: Issue; entry: IssueEntry; earlier: IssueEntry | undefined; linked: boolean; closed: ClosedQuestion | null; changesRequest: IssueEntry | undefined }) {
    const EntryIcon = ENTRY_ICONS[entry.kind] ?? DotFillIcon;
    const replaced = null !== entry.replacedBy;
    const text = (entry.body ?? entry.note ?? entry.reviewNote ?? '').trim();
    const content = ('' !== text || entry.questions.length > 0) && (
        <div className={styles.entryText}>
            {'' !== text && <MarkdownBody markdown={text} />}
            {entry.questions.length > 0 && <QuestionList questions={entry.questions} />}
        </div>
    );
    const line = (
        <>
            <ActorName actor={entry.actor} />
            {null !== entry.section && (
                <>
                    {' '}
                    <Label variant="primary" data-testid="section-label">
                        {SECTION_TITLES[entry.section]}
                    </Label>
                </>
            )}
            <CommentLabelBadge label={entry.label} /> <EntryAction issue={issue} entry={entry} /> <RelativeTime datetime={entry.createdAt} />
            {replaced && (
                <>
                    {' '}
                    <Label variant="secondary">{closed?.state ?? 'Replaced'}</Label>
                </>
            )}
        </>
    );

    return (
        <Timeline.Item id={entryId(entry.id)} tabIndex={-1} className={linked ? styles.linked : undefined} condensed={replaced} data-testid="issue-entry" data-kind={entry.kind} data-replaced={replaced}>
            <Timeline.Badge variant={'plan_approved' === entry.kind && !replaced ? 'success' : changesRequest?.id === entry.id ? 'danger' : undefined}>
                <EntryIcon aria-hidden />
            </Timeline.Badge>
            <Timeline.Body className={styles.entry}>
                {isCurrentVersion(issue, entry) && 'notes' === entry.section ? (
                    <div className={styles.entryHeader}>
                        <Details className={styles.replaced} data-testid="notes-entry">
                            <Details.Summary className={styles.replacedLine}>
                                <ChevronRightIcon className={styles.chevron} aria-hidden /> {line}
                            </Details.Summary>
                            <SectionEntry issue={issue} section={entry.section} line={null} author={entry.actor} earlier={earlier} changesRequested={null} />
                        </Details>
                    </div>
                ) : isCurrentVersion(issue, entry) ? (
                    <SectionEntry
                        issue={issue}
                        section={entry.section}
                        line={line}
                        author={entry.actor}
                        earlier={earlier}
                        changesRequested={'plan' === entry.section && undefined !== changesRequest ? <ChangesRequested request={changesRequest} /> : null}
                    />
                ) : null !== entry.replacedBy ? (
                    <div className={styles.entryHeader}>
                        {false === content ? (
                            <p className={styles.entryLine}>{line}</p>
                        ) : (
                            <Details className={styles.replaced}>
                                <Details.Summary className={styles.replacedLine}>
                                    <ChevronRightIcon className={styles.chevron} aria-hidden /> {line}
                                </Details.Summary>
                                {content}
                            </Details>
                        )}
                        <EntryLink id={entry.replacedBy}>{closed?.link ?? 'Newer version'}</EntryLink>
                    </div>
                ) : (
                    <>
                        <p className={styles.entryLine}>{line}</p>
                        {content}
                    </>
                )}
            </Timeline.Body>
        </Timeline.Item>
    );
}

function ChangesRequested({ request }: { request: IssueEntry }) {
    return (
        <div className={`${styles.sectionFooter} ${styles.changesRequested}`} data-testid="plan-changes-requested">
            <Label variant="danger">Changes requested</Label>
            <span>
                <ActorName actor={request.actor} /> asked for changes of this plan.
            </span>
            <EntryLink id={request.id}>Read the request</EntryLink>
        </div>
    );
}

/** A status change names its party by its role, as the issue names the party now (D134). */
function EntryAction({ issue, entry }: { issue: Issue; entry: IssueEntry }) {
    const reviewedBy = null === entry.reviewer ? '' : `, reviewed by ${entry.reviewer},`;
    const party = null === entry.waitingFor ? null : partyName(issue[entry.waitingFor]);

    if ('status_changed' === entry.kind && null !== entry.from && entry.from === entry.to && null !== party) {
        return `handed the issue to ${party}`;
    }
    if ('status_changed' === entry.kind && null !== entry.from && null !== entry.to) {
        return (
            <>
                changed the status from <IssueStatusLabel status={entry.from} /> to <IssueStatusLabel status={entry.to} />
                {null !== party && `, waiting for ${party}`}
                {reviewedBy}
            </>
        );
    }
    if ('section_changed' === entry.kind && null !== entry.section) {
        return `changed the ${SECTION_TITLES[entry.section].toLowerCase()}`;
    }
    if ('plan_approved' === entry.kind) {
        return `approved the plan${reviewedBy}`;
    }
    if ('comment' === entry.kind && null !== entry.planRevision) {
        return 'requested changes to the plan';
    }

    return 'comment' === entry.kind ? 'commented' : entry.kind;
}
