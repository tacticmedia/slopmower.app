import { ActionList, ActionMenu, Heading, Link, RelativeTime } from '@primer/react';
import { Link as RouterLink, useLoaderData, useSearchParams } from 'react-router';
import { ISSUE_STATUSES, type IssueStatus } from '../../api/types/common';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import headerStyles from '../../requests/RequestListHeader.module.css';
import rowStyles from '../../requests/RequestRow.module.css';
import listStyles from '../requests/RequestList.module.css';
import { ISSUE_STATUS_TEXT, IssueStatusLabel } from './IssueStatusLabel';
import styles from './Issues.module.css';
import { type ProjectData, statusFilter } from './issueData';
import { APPROVAL_MODE_NAMES } from './ProjectsRoute';

const STATUS_OPTIONS: readonly (IssueStatus | null)[] = [null, ...ISSUE_STATUSES];

/** The issues of one project, newest change first. The status filter is the URL search parameter `status` of I1. */
export function ProjectRoute() {
    const { project, issues } = useLoaderData<ProjectData>();
    const [searchParams, setSearchParams] = useSearchParams();
    const filter = statusFilter(searchParams);
    useDocumentTitle(project.name);

    return (
        <div className={styles.page}>
            <header className={styles.header}>
                <Link as={RouterLink} to="/projects" className={styles.back}>
                    Projects
                </Link>
                <Heading as="h1">{project.name}</Heading>
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
                        <ActionMenu>
                            <ActionMenu.Button size="small">Status: {null === filter ? 'All' : ISSUE_STATUS_TEXT[filter]}</ActionMenu.Button>
                            <ActionMenu.Overlay>
                                <ActionList selectionVariant="single">
                                    {STATUS_OPTIONS.map((status) => (
                                        <ActionList.Item key={status ?? 'all'} selected={status === filter} onSelect={() => setSearchParams(null === status ? {} : { status })}>
                                            {null === status ? 'All' : ISSUE_STATUS_TEXT[status]}
                                        </ActionList.Item>
                                    ))}
                                </ActionList>
                            </ActionMenu.Overlay>
                        </ActionMenu>
                    </div>
                </div>
                {0 === issues.length ? (
                    <p className={listStyles.empty} data-testid="issue-list-empty">
                        {null === filter ? (
                            'No issues yet.'
                        ) : (
                            <>
                                No issue has this status.{' '}
                                <Link as={RouterLink} to={{ search: '' }}>
                                    Clear the filter
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
                                        </span>
                                    </div>
                                    <div className={rowStyles.meta}>{issue.key}</div>
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
