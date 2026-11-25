import { ActionList, ActionMenu, Button, Heading, Link } from '@primer/react';
import { Link as RouterLink, useLoaderData, useSearchParams } from 'react-router';
import { ISSUE_CATEGORIES, ISSUE_STATUSES, type IssueCategory, type IssueStatus } from '../../api/types/common';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import headerStyles from '../../requests/RequestListHeader.module.css';
import listStyles from '../requests/RequestList.module.css';
import { APPROVAL_MODE_NAMES } from './ApprovalModeField';
import { IssueRankList } from './IssueRankList';
import { ISSUE_STATUS_TEXT } from './IssueStatusLabel';
import styles from './Issues.module.css';
import { ACTIVITY_FILTERS, activityFilter, categoryFilter, type IssueSort, type ProjectData, sortFilter, statusFilter } from './issueData';

const STATUS_OPTIONS: readonly (IssueStatus | null)[] = [null, ...ISSUE_STATUSES];
const CATEGORY_OPTIONS: readonly (IssueCategory | null)[] = [null, ...ISSUE_CATEGORIES];
const CATEGORY_FILTER_TEXT: Record<IssueCategory, string> = { bug: 'Bug reports', change: 'Change requests' };
const SORT_TEXT: Record<IssueSort, string> = { rank: 'Rank', updated: 'Last update' };
const ACTIVITY_PARAMETERS = ['activeWithinHours', 'idleForHours', 'noActivity'] as const;

/**
 * The issues of one project in two lists, the bug reports and the change requests, in rank order by default (D137).
 * The filters and the sort are the URL search parameters of I1.
 */
export function ProjectRoute() {
    const { project, issues } = useLoaderData<ProjectData>();
    const [searchParams, setSearchParams] = useSearchParams();
    const filter = statusFilter(searchParams);
    const category = categoryFilter(searchParams);
    const sort = sortFilter(searchParams);
    const activity = activityFilter(searchParams);
    const setFilters = (changes: Record<string, string | null>): void =>
        setSearchParams((current) => {
            const next = new URLSearchParams(current);
            for (const [name, value] of Object.entries(changes)) {
                if (null === value) {
                    next.delete(name);
                } else {
                    next.set(name, value);
                }
            }

            return next;
        });
    const setFilter = (name: 'status' | 'category', value: string | null): void => setFilters({ [name]: value });
    // Without a status, the rank order holds the open issues only.
    const anyStatus = 'rank' === sort ? 'Open' : 'All';
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
                        <Link as={RouterLink} to={`/projects/${project.key}/board`} className={styles.toolLink}>
                            Board
                        </Link>
                        <ActionMenu>
                            <ActionMenu.Button size="small">Status: {null === filter ? anyStatus : ISSUE_STATUS_TEXT[filter]}</ActionMenu.Button>
                            <ActionMenu.Overlay>
                                <ActionList selectionVariant="single">
                                    {STATUS_OPTIONS.map((status) => (
                                        <ActionList.Item key={status ?? 'all'} selected={status === filter} onSelect={() => setFilter('status', status)}>
                                            {null === status ? anyStatus : ISSUE_STATUS_TEXT[status]}
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
                        <ActionMenu>
                            <ActionMenu.Button size="small">Activity: {activity.text}</ActionMenu.Button>
                            <ActionMenu.Overlay>
                                <ActionList selectionVariant="single">
                                    {ACTIVITY_FILTERS.map((option) => (
                                        <ActionList.Item
                                            key={option.text}
                                            selected={option === activity}
                                            onSelect={() => setFilters({ ...Object.fromEntries(ACTIVITY_PARAMETERS.map((name) => [name, null])), ...(null === option.name ? {} : { [option.name]: option.value }) })}
                                        >
                                            {option.text}
                                        </ActionList.Item>
                                    ))}
                                </ActionList>
                            </ActionMenu.Overlay>
                        </ActionMenu>
                        <ActionMenu>
                            <ActionMenu.Button size="small">Sort: {SORT_TEXT[sort]}</ActionMenu.Button>
                            <ActionMenu.Overlay>
                                <ActionList selectionVariant="single">
                                    {(['rank', 'updated'] as const).map((option) => (
                                        <ActionList.Item key={option} selected={option === sort} onSelect={() => setFilters({ sort: 'rank' === option ? null : option })}>
                                            {SORT_TEXT[option]}
                                        </ActionList.Item>
                                    ))}
                                </ActionList>
                            </ActionMenu.Overlay>
                        </ActionMenu>
                    </div>
                </div>
                {0 === issues.length ? (
                    <p className={listStyles.empty} data-testid="issue-list-empty">
                        {null === filter && null === category && null === activity.name ? (
                            'rank' === sort ? (
                                'No open issues.'
                            ) : (
                                'No issues yet.'
                            )
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
                    <div data-testid="issue-list">
                        {ISSUE_CATEGORIES.map((known) => {
                            const ofCategory = issues.filter((issue) => issue.category === known);

                            return 0 === ofCategory.length ? null : <IssueRankList key={known} heading={CATEGORY_FILTER_TEXT[known]} issues={ofCategory} rankable={'rank' === sort} />;
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}
