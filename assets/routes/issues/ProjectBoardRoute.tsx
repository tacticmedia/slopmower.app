import { Heading, Link, RelativeTime, VisuallyHidden } from '@primer/react';
import { useEffect, useId, useRef, useState } from 'react';
import { Link as RouterLink, useLoaderData } from 'react-router';
import { ISSUE_STATUSES, type IssueStatus } from '../../api/types/common';
import type { IssueSummary } from '../../api/types/issue';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import boardStyles from './IssueBoard.module.css';
import { BugLabel, ISSUE_STATUS_TEXT, NoApprovalLabel } from './IssueStatusLabel';
import styles from './Issues.module.css';
import type { ProjectData } from './issueData';
import { WaitingLabel } from './WaitingLabel';

const COLUMNS = ISSUE_STATUSES.filter((status) => 'backlog' !== status);

/** These columns show only their newest cards. The list with the status has the others. */
const ENDED: readonly IssueStatus[] = ['done', 'wont_do'];
const ENDED_CARDS = 10;

/** One sentence per issue of both lists whose status changed. */
function moves(before: IssueSummary[], after: IssueSummary[]): string {
    const statusBefore = new Map(before.map(({ key, status }) => [key, status]));

    return after
        .filter(({ key, status }) => statusBefore.has(key) && statusBefore.get(key) !== status)
        .map(({ key, status }) => `${key} moved to ${ISSUE_STATUS_TEXT[status]}.`)
        .join(' ');
}

function Column({ status, issues, projectKey, keepFocus }: { status: IssueStatus; issues: IssueSummary[]; projectKey: string; keepFocus: (key: string) => (link: HTMLAnchorElement | null) => void }) {
    const headingId = useId();
    const shown = ENDED.includes(status) ? issues.slice(0, ENDED_CARDS) : issues;

    return (
        <div className={boardStyles.column} data-testid="board-column">
            <Heading as="h2" variant="small" id={headingId} className={boardStyles.heading}>
                {ISSUE_STATUS_TEXT[status]} <span className={boardStyles.count}>{issues.length}</span>
            </Heading>
            {0 === issues.length ? (
                <p className={boardStyles.empty}>No issues</p>
            ) : (
                // Safari drops the list role of a list without markers.
                <ul className={boardStyles.cards} role="list" aria-labelledby={headingId}>
                    {shown.map((issue) => (
                        <li key={issue.key}>
                            <RouterLink to={`/issues/${issue.key}`} className={boardStyles.card} data-issue-key={issue.key} ref={keepFocus(issue.key)}>
                                <span className={styles.key}>{issue.key}</span> <span className={boardStyles.title}>{issue.title}</span>{' '}
                                <span className={boardStyles.meta}>
                                    <WaitingLabel issue={issue} /> <BugLabel category={issue.category} /> <NoApprovalLabel approval={issue.approval} />{' '}
                                    <span>
                                        updated <RelativeTime datetime={issue.updatedAt} />
                                    </span>
                                </span>
                            </RouterLink>
                        </li>
                    ))}
                </ul>
            )}
            {shown.length < issues.length && (
                <Link as={RouterLink} to={`/projects/${projectKey}?status=${status}`} className={boardStyles.more}>
                    All {issues.length} issues in {ISSUE_STATUS_TEXT[status]}
                </Link>
            )}
        </div>
    );
}

/** The issues of one project outside the backlog, one column per status, from I1. A view: no card moves by hand. */
export function ProjectBoardRoute() {
    const { project, issues } = useLoaderData<ProjectData>();
    const [previousIssues, setPreviousIssues] = useState(issues);
    const [announcement, setAnnouncement] = useState('');
    if (previousIssues !== issues) {
        setPreviousIssues(issues);
        setAnnouncement(moves(previousIssues, issues));
    }
    const focusedKey = useRef<string | null>(null);
    // A key that no card took back in its commit is stale, for example of a card that moved to the backlog.
    useEffect(() => {
        focusedKey.current = null;
    });
    const backlog = issues.filter(({ status }) => 'backlog' === status).length;
    useDocumentTitle(`Board of ${project.name}`);

    /**
     * A card that moves to another column is a new element, which takes the focus back from the card that it replaces.
     * React attaches the ref of a new element after it removed the old one. A card that React moves inside its list
     * is the same element, and React gives the focus back to it.
     */
    const keepFocus =
        (key: string) =>
        (link: HTMLAnchorElement | null): void => {
            if (null === link) {
                // React detaches the ref before it removes the element, so the focus is still on it.
                if (document.activeElement instanceof HTMLElement && key === document.activeElement.dataset.issueKey) {
                    focusedKey.current = key;
                }
            } else if (key === focusedKey.current) {
                focusedKey.current = null;
                link.focus();
            }
        };

    return (
        <div className={styles.page} data-full-width>
            <header className={styles.header}>
                <Link as={RouterLink} to={`/projects/${project.key}`} className={styles.back}>
                    Issues of {project.name}
                </Link>
                <Heading as="h1">Board of {project.name}</Heading>
            </header>
            <div className={boardStyles.board} data-testid="issue-board">
                {COLUMNS.map((status) => (
                    <Column key={status} status={status} issues={issues.filter((issue) => status === issue.status)} projectKey={project.key} keepFocus={keepFocus} />
                ))}
            </div>
            {backlog > 0 && (
                <p className={styles.state}>
                    <Link as={RouterLink} to={`/projects/${project.key}?status=backlog`}>
                        {backlog} {1 === backlog ? 'issue' : 'issues'} in the backlog
                    </Link>
                </p>
            )}
            <VisuallyHidden role="status">{announcement}</VisuallyHidden>
        </div>
    );
}
