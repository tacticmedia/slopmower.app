import { Heading, Link, RelativeTime } from '@primer/react';
import { Link as RouterLink, useLoaderData, useSearchParams } from 'react-router';
import { MarkdownBody } from '../../comments/MarkdownBody';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import headerStyles from '../../requests/RequestListHeader.module.css';
import rowStyles from '../../requests/RequestRow.module.css';
import listStyles from '../requests/RequestList.module.css';
import { ActorName, CommentLabelBadge, CommentLabelFilter, NoCommentWithLabel } from './CommentParts';
import styles from './Issues.module.css';
import { labelFilter, type ProjectCommentsData } from './issueData';

/** The comments of one project across its issues, newest first. The label filter is the URL search parameter `label` of I5. */
export function ProjectCommentsRoute() {
    const { project, comments } = useLoaderData<ProjectCommentsData>();
    const filter = labelFilter(useSearchParams()[0]);
    useDocumentTitle(`Comments of ${project.name}`);

    return (
        <div className={styles.page}>
            <header className={styles.header}>
                <Link as={RouterLink} to={`/projects/${project.key}`} className={styles.back}>
                    Issues of {project.name}
                </Link>
                <Heading as="h1">Comments of {project.name}</Heading>
            </header>
            <div className={listStyles.box}>
                <div className={headerStyles.header} data-testid="comment-filters">
                    <span className={styles.count}>
                        {comments.length} {1 === comments.length ? 'comment' : 'comments'}
                    </span>
                    <div className={headerStyles.tools}>
                        <CommentLabelFilter />
                    </div>
                </div>
                {0 === comments.length ? (
                    <p className={listStyles.empty} data-testid="comment-list-empty">
                        {null === filter ? 'No comments yet.' : <NoCommentWithLabel />}
                    </p>
                ) : (
                    <ul className={listStyles.list} aria-label="Comments" data-testid="comment-list">
                        {comments.map((comment) => (
                            <li key={comment.id} className={`${rowStyles.row} ${styles.row}`} data-testid="comment-row">
                                <div className={rowStyles.body}>
                                    <div className={rowStyles.headline}>
                                        <Link as={RouterLink} to={`/issues/${comment.issue.key}`} className={rowStyles.title}>
                                            {comment.issue.title}
                                        </Link>
                                        <span className={rowStyles.labels}>
                                            <CommentLabelBadge label={comment.label} />
                                        </span>
                                    </div>
                                    <div className={rowStyles.meta}>
                                        <span className={rowStyles.metaItem}>{comment.issue.key}</span>
                                        <span className={rowStyles.metaItem}>
                                            <ActorName actor={comment.actor} />
                                        </span>
                                    </div>
                                    <MarkdownBody markdown={comment.body} />
                                </div>
                                <div className={`${rowStyles.aside} ${styles.commentTime}`}>
                                    <RelativeTime datetime={comment.createdAt} />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
