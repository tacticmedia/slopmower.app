import { ActionList, ActionMenu, Button, Heading, Link, RelativeTime } from '@primer/react';
import { Link as RouterLink, useLoaderData, useSearchParams } from 'react-router';
import { ISSUE_CATEGORIES, ISSUE_STATUSES, type IssueCategory, type IssueStatus } from '../../api/types/common';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import headerStyles from '../../requests/RequestListHeader.module.css';
import rowStyles from '../../requests/RequestRow.module.css';
import listStyles from '../requests/RequestList.module.css';
import { BugLabel, ISSUE_STATUS_TEXT, IssueStatusLabel, NoApprovalLabel } from './IssueStatusLabel';
import { WaitingLabel } from './WaitingLabel';
import styles from './Issues.module.css';
import { categoryFilter, type ProjectData, statusFilter } from './issueData';
import { APPROVAL_MODE_NAMES } from './ApprovalModeField';

const STATUS_OPTIONS: readonly (IssueStatus | null)[] = [null, ...ISSUE_STATUSES];
const CATEGORY_OPTIONS: readonly (IssueCategory | null)[] = [null, ...ISSUE_CATEGORIES];
const CATEGORY_FILTER_TEXT: Record<IssueCategory, string> = { bug: 'Bug reports', change: 'Change requests' };

/** The issues of one project, newest change first. The filters are the URL search parameters `status` and `category` of I1. */
export function ProjectRoute() {
    const { project, issues } = useLoaderData<ProjectData>();
    const [searchParams, setSearchParams] = useSearchParams();
    const filter = statusFilter(searchParams);
    const category = categoryFilter(searchParams);
    const setFilter = (name: 'status' | 'category', value: string | null): void =>
        setSearchParams((current) => {
            const next = new URLSearchParams(current);
            if (null === value) {
                next.delete(name);
            } else {
                next.set(name, value);
            }

            return next;
        });
    useDocumentTitle(project.name);

    return (
        <div className={styles.page}>
            <header className={styles.header}>
                <Link as={RouterLink} to="/projects" className={styles.back}>
                    Projects
                </Link>
                <div className={styles.titleRow}>
                    <Heading as="h1">{project.name}</Heading>
                    <div className={styles.actions}>
                        <Button as={RouterLink} to={`/projects/${project.key}/settings`}>
                            Project settings
                        </Button>
                        <Button as={RouterLink} to={`/projects/${project.key}/issues/new`} variant="primary">
                            New issue
                        </Button>
                    </div>
                </div>
                <p className={styles.state}>
                    <span className={styles.key}>{project.key}</span>
                    <span className={styles.muted}>
                        {APPROVAL_MODE_NAMES[project.approvalMode]}
                        {project.repositories.length > 0 && ` · ${project.repositories.join(', ')}`}
                    </span>
                </p>
            </header>
            <div className={listStyles.box}>
                <div className={headerStyles.header} data-testid="issue-filters">
                    <span className={styles.count}>
                        {issues.length} {1 === issues.length ? 'issue' : 'issues'}
                    </span>
                    <div className={headerStyles.tools}>
                        <Link as={RouterLink} to={`/projects/${project.key}/comments`} className={styles.toolLink}>
                            Comments
                        </Link>
                        <Link as={RouterLink} to={`/projects/${project.key}/graph`} className={styles.toolLink}>
                            Graph
                        </Link>
                        <ActionMenu>
                            <ActionMenu.Button size="small">Status: {null === filter ? 'All' : ISSUE_STATUS_TEXT[filter]}</ActionMenu.Button>
                            <ActionMenu.Overlay>
                                <ActionList selectionVariant="single">
                                    {STATUS_OPTIONS.map((status) => (
                                        <ActionList.Item key={status ?? 'all'} selected={status === filter} onSelect={() => setFilter('status', status)}>
                                            {null === status ? 'All' : ISSUE_STATUS_TEXT[status]}
                                        </ActionList.Item>
                                    ))}
                                </ActionList>
                            </ActionMenu.Overlay>
                        </ActionMenu>
                        <ActionMenu>
                            <ActionMenu.Button size="small">Category: {null === category ? 'All' : CATEGORY_FILTER_TEXT[category]}</ActionMenu.Button>
                            <ActionMenu.Overlay>
                                <ActionList selectionVariant="single">
                                    {CATEGORY_OPTIONS.map((option) => (
                                        <ActionList.Item key={option ?? 'all'} selected={option === category} onSelect={() => setFilter('category', option)}>
                                            {null === option ? 'All' : CATEGORY_FILTER_TEXT[option]}
                                        </ActionList.Item>
                                    ))}
                                </ActionList>
                            </ActionMenu.Overlay>
                        </ActionMenu>
                    </div>
                </div>
                {0 === issues.length ? (
                    <p className={listStyles.empty} data-testid="issue-list-empty">
                        {null === filter && null === category ? (
                            'No issues yet.'
                        ) : (
                            <>
                                No issue matches the filters.{' '}
                                <Link as={RouterLink} to={{ search: '' }}>
                                    Clear the filters
                                </Link>
                            </>
                        )}
                    </p>
                ) : (
                    <ul className={listStyles.list} aria-label="Issues" data-testid="issue-list">
                        {issues.map((issue) => (
                            <li key={issue.key} className={`${rowStyles.row} ${styles.row}`} data-testid="issue-row">
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
                                        updated <RelativeTime datetime={issue.updatedAt} />
                                    </span>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
