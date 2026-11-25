import { GrabberIcon } from '@primer/octicons-react';
import { ActionList, ActionMenu, IconButton, Link, RelativeTime } from '@primer/react';
import { type DragEvent, useState } from 'react';
import { Link as RouterLink, useFetcher } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import type { RankIssueInput } from '../../api/types/inputs';
import type { IssueSummary } from '../../api/types/issue';
import rowStyles from '../../requests/RequestRow.module.css';
import listStyles from '../requests/RequestList.module.css';
import { BugLabel, IssueStatusLabel, NoApprovalLabel } from './IssueStatusLabel';
import styles from './Issues.module.css';
import type { RankIntent } from './issueData';
import { WaitingLabel } from './WaitingLabel';

type Drop = { key: string; after: boolean };

/**
 * The issues of one category in the order of I1. In the rank order, `rankable`, a row shows the age of its last
 * activity, and its handle "Move {key}" sends I13: a drag within this list, or its menu without a pointer (D137). Only
 * this list knows its drag, so a drop on another list does nothing. Else a row shows its update age, the sort key.
 */
export function IssueRankList({ heading, issues, rankable }: { heading: string; issues: IssueSummary[]; rankable: boolean }) {
    const fetcher = useFetcher<ActionResult>();
    const busy = 'idle' !== fetcher.state;
    const [moved, setMoved] = useState<string | null>(null);
    const [dragged, setDragged] = useState<string | null>(null);
    const [drop, setDrop] = useState<Drop | null>(null);
    const move = (issueKey: string, input: RankIssueInput): void => {
        const intent: RankIntent = { issueKey, input };
        setMoved(issueKey);
        void fetcher.submit(intent, { method: 'post', encType: 'application/json' });
    };
    const place = issues.findIndex(({ key }) => key === moved);
    const status = busy || null === moved ? '' : (fetcher.data?.error ?? (place < 0 ? '' : `${moved} is now ${place + 1} of ${issues.length}.`));
    const endDrag = (): void => {
        setDragged(null);
        setDrop(null);
    };
    const dragOver = (event: DragEvent<HTMLLIElement>, key: string): void => {
        if (null === dragged || dragged === key) {
            return;
        }
        event.preventDefault();
        const box = event.currentTarget.getBoundingClientRect();
        const after = event.clientY > box.top + box.height / 2;
        if (drop?.key !== key || drop.after !== after) {
            setDrop({ key, after });
        }
    };
    const dropOn = (event: DragEvent<HTMLLIElement>): void => {
        event.preventDefault();
        if (null !== dragged && null !== drop && !busy) {
            move(dragged, drop.after ? { after: drop.key } : { before: drop.key });
        }
        endDrag();
    };

    return (
        <div className={styles.rankList}>
            <div className={styles.rankListHeader}>
                <h2 className={styles.rankListHeading}>{heading}</h2>
                <span role="status" className={styles.muted} data-testid="move-status">
                    {status}
                </span>
            </div>
            <ul className={listStyles.list} aria-label={heading}>
                {issues.map((issue, index) => {
                    const first = issues[0];
                    const last = issues.at(-1);
                    const marker = drop?.key === issue.key ? (drop.after ? styles.dropAfter : styles.dropBefore) : '';

                    return (
                        <li
                            key={issue.key}
                            className={`${rowStyles.row} ${styles.row} ${rankable ? styles.rankedRow : ''} ${marker}`}
                            data-testid="issue-row"
                            onDragOver={(event) => dragOver(event, issue.key)}
                            onDrop={dropOn}
                        >
                            {rankable && undefined !== first && undefined !== last && (
                                <div className={rowStyles.icon}>
                                    <ActionMenu>
                                        <ActionMenu.Anchor>
                                            <IconButton
                                                icon={GrabberIcon}
                                                aria-label={`Move ${issue.key}`}
                                                variant="invisible"
                                                size="small"
                                                className={styles.handle}
                                                draggable
                                                onDragStart={(event) => {
                                                    event.dataTransfer.effectAllowed = 'move';
                                                    event.dataTransfer.setData('text/plain', issue.key);
                                                    setDragged(issue.key);
                                                }}
                                                onDragEnd={endDrag}
                                            />
                                        </ActionMenu.Anchor>
                                        <ActionMenu.Overlay>
                                            <ActionList>
                                                <ActionList.Item disabled={busy || 0 === index} onSelect={() => move(issue.key, { before: first.key })}>
                                                    Move to top
                                                </ActionList.Item>
                                                <ActionList.Item disabled={busy || 0 === index} onSelect={() => move(issue.key, { before: issues[index - 1]?.key ?? first.key })}>
                                                    Move up
                                                </ActionList.Item>
                                                <ActionList.Item disabled={busy || last === issue} onSelect={() => move(issue.key, { after: issues[index + 1]?.key ?? last.key })}>
                                                    Move down
                                                </ActionList.Item>
                                                <ActionList.Item disabled={busy || last === issue} onSelect={() => move(issue.key, { after: last.key })}>
                                                    Move to bottom
                                                </ActionList.Item>
                                            </ActionList>
                                        </ActionMenu.Overlay>
                                    </ActionMenu>
                                </div>
                            )}
                            <div className={rowStyles.body}>
                                <div className={rowStyles.headline}>
                                    <Link as={RouterLink} to={`/issues/${issue.key}`} className={rowStyles.title}>
                                        {issue.title}
                                    </Link>
                                    <span className={rowStyles.labels}>
                                        <IssueStatusLabel status={issue.status} />
                                        <WaitingLabel issue={issue} />
                                        <BugLabel category={issue.category} />
                                        <NoApprovalLabel approval={issue.approval} />
                                    </span>
                                </div>
                                <div className={rowStyles.meta}>
                                    {issue.key}
                                    {null !== issue.claim && ` · held by ${issue.claim.session}`}
                                </div>
                            </div>
                            <div className={rowStyles.aside}>
                                <span>
                                    {!rankable ? (
                                        <>
                                            updated <RelativeTime datetime={issue.updatedAt} />
                                        </>
                                    ) : null === issue.lastActivityAt ? (
                                        'No activity yet'
                                    ) : (
                                        <>
                                            active <RelativeTime datetime={issue.lastActivityAt} />
                                        </>
                                    )}
                                </span>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
