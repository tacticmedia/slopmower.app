import { ArrowSwitchIcon, CheckIcon, ChevronRightIcon, CommentIcon, DotFillIcon, type Icon, PencilIcon } from '@primer/octicons-react';
import { Button, Details, Label, Link, RelativeTime, Timeline } from '@primer/react';
import { type ReactNode, useEffect } from 'react';
import { Link as RouterLink, useLocation } from 'react-router';
import { type CommentLabel, ISSUE_SECTIONS, type IssueSection as Section } from '../../api/types/common';
import type { Issue, IssueEntry } from '../../api/types/issue';
import { MarkdownBody } from '../../comments/MarkdownBody';
import { QuestionList } from '../../comments/Questions';
import { ActorName, CommentLabelBadge, CommentLabelFilter, NoCommentWithLabel } from './CommentParts';
import { hasText, SECTION_TITLES, SectionEditor, useSectionWrite } from './IssueSection';
import { IssueStatusLabel } from './IssueStatusLabel';
import styles from './Issues.module.css';
import { labelFilter } from './issueData';
import { SectionEntry } from './SectionEntry';

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
 * a plan approval are entries, oldest first. A replaced entry stays as one closed line.
 */
export function IssueTimeline({ issue, thread }: { issue: Issue; thread: IssueEntry[] }) {
    const { search, hash, key } = useLocation();
    const filter = labelFilter(new URLSearchParams(search));
    const entries = null === filter ? thread : thread.filter((entry) => filter === entry.label);
    const closed = new Map(thread.flatMap(({ label, replaces }) => (null === label || undefined === CLOSED_QUESTIONS[label] ? [] : [[replaces, CLOSED_QUESTIONS[label]] as const])));

    // The browser does not move to the hash of a navigation of the router. `key` moves again for the same link.
    useEffect(() => {
        const entry = document.getElementById(hash.slice(1));
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

                    return undefined === version ? (
                        <WriteSection key={section} issue={issue} section={section} />
                    ) : (
                        <EntryLink key={section} id={version.id}>
                            {SECTION_TITLES[section]}
                        </EntryLink>
                    );
                })}
            </div>
            {entries.length > 0 && (
                <Timeline clipSidebar data-testid="issue-thread">
                    {entries.map((entry) => (
                        <TimelineEntry
                            key={isCurrentVersion(issue, entry) ? `section:${entry.section}` : entry.id}
                            issue={issue}
                            entry={entry}
                            linked={hash === `#${entryId(entry.id)}`}
                            closed={closed.get(entry.id) ?? null}
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
 * @param linked The hash of the URL names the entry
 * @param closed The entry is a question comment that the answers or a dismissal replaced
 */
function TimelineEntry({ issue, entry, linked, closed }: { issue: Issue; entry: IssueEntry; linked: boolean; closed: ClosedQuestion | null }) {
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
            <CommentLabelBadge label={entry.label} /> <EntryAction entry={entry} /> <RelativeTime datetime={entry.createdAt} />
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
            <Timeline.Badge variant={'plan_approved' === entry.kind && !replaced ? 'success' : undefined}>
                <EntryIcon aria-hidden />
            </Timeline.Badge>
            <Timeline.Body className={styles.entry}>
                {isCurrentVersion(issue, entry) ? (
                    <SectionEntry issue={issue} section={entry.section} line={line} author={entry.actor} />
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
        return `changed the ${SECTION_TITLES[entry.section].toLowerCase()}`;
    }
    if ('plan_approved' === entry.kind) {
        return `approved the plan${reviewedBy}`;
    }

    return 'comment' === entry.kind ? 'commented' : entry.kind;
}
