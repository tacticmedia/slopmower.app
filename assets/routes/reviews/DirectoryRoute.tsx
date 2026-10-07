import { BookIcon, CheckIcon, FileDirectoryFillIcon, FileIcon, FileSubmoduleIcon, FileSymlinkFileIcon, type Icon } from '@primer/octicons-react';
import { Link, Spinner } from '@primer/react';
import { lazy, Suspense, useCallback, useMemo } from 'react';
import { Link as RouterLink, useLoaderData, useRouteLoaderData } from 'react-router';
import type { TreeEntry } from '../../api/types/tree-entry';
import { FileHeader } from '../../review/FileHeader';
import { directoryHref, fileHref } from '../../review/fileHref';
import { formatSize } from '../../review/pathSegments';
import { readmeUrl } from '../../review/readmeUrl';
import styles from './DirectoryRoute.module.css';
import type { ReviewData } from './reviewData';
import type { DirectoryView } from './treeData';

const MarkdownBody = lazy(() => import('../../comments/MarkdownBody').then(({ MarkdownBody }) => ({ default: MarkdownBody })));

/**
 * A directory at the review commit, as on GitHub: a table of its entries, then its README.
 */
export function DirectoryRoute() {
    const { listing, readme } = useLoaderData<DirectoryView>();
    const reviewData = useRouteLoaderData<ReviewData>('review');
    if (undefined === reviewData) {
        throw new Error('The directory route renders below the review route.');
    }
    const { review, viewedPaths } = reviewData;
    const viewed = useMemo(() => new Set(viewedPaths), [viewedPaths]);
    const { dir, items } = listing;
    const directories = useMemo(() => new Set(items.filter(({ type }) => 'directory' === type).map(({ path }) => path)), [items]);
    const resolveUrl = useCallback((url: string, attribute: 'href' | 'src') => readmeUrl(url, attribute, { reviewId: review.id, dir, directories }), [review.id, dir, directories]);

    return (
        <section className={styles.directory} aria-label={'' === dir ? review.repositoryName : dir}>
            <FileHeader reviewId={review.id} repositoryName={review.repositoryName} path={dir} directory />
            <table className={styles.table} aria-label="Folders and files">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col" className={styles.size}>
                            Size
                        </th>
                        <th scope="col" className={styles.viewed}>
                            Viewed
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {'' !== dir && (
                        <tr>
                            <td colSpan={3}>
                                <Link as={RouterLink} to={directoryHref(review.id, dir.slice(0, Math.max(dir.lastIndexOf('/'), 0)))} aria-label="Parent directory" className={styles.name}>
                                    <FileDirectoryFillIcon className={styles.directoryIcon} />
                                    ..
                                </Link>
                            </td>
                        </tr>
                    )}
                    {items.map((entry) => (
                        <EntryRow key={entry.path} reviewId={review.id} entry={entry} viewed={viewed.has(entry.path)} />
                    ))}
                </tbody>
            </table>
            {null !== readme && (
                <section className={styles.readme} aria-label={readme.entry.name}>
                    <h2 className={styles.readmeTitle}>
                        <BookIcon />
                        {readme.entry.name}
                    </h2>
                    <Suspense fallback={<Spinner srText="Loading the README" />}>
                        <MarkdownBody markdown={readme.markdown} resolveUrl={resolveUrl} readme />
                    </Suspense>
                </section>
            )}
        </section>
    );
}

const ENTRY_ICONS: Partial<Record<string, Icon>> = { directory: FileDirectoryFillIcon, symlink: FileSymlinkFileIcon, submodule: FileSubmoduleIcon };

/** V7 refuses submodules, so they are not links. */
function EntryRow({ reviewId, entry, viewed }: { reviewId: string; entry: TreeEntry; viewed: boolean }) {
    const Icon = ENTRY_ICONS[entry.type] ?? FileIcon;
    const href = 'directory' === entry.type ? directoryHref(reviewId, entry.path) : 'submodule' === entry.type ? null : fileHref(reviewId, entry.path);
    const icon = <Icon className={'directory' === entry.type ? styles.directoryIcon : styles.fileIcon} />;

    return (
        <tr>
            <td>
                {null === href ? (
                    <span className={styles.name}>
                        {icon}
                        {entry.name}
                    </span>
                ) : (
                    <Link as={RouterLink} to={href} className={styles.name}>
                        {icon}
                        {entry.name}
                    </Link>
                )}
            </td>
            <td className={styles.size}>{null === entry.sizeBytes ? '' : formatSize(entry.sizeBytes)}</td>
            <td className={styles.viewed}>{viewed && <CheckIcon aria-label="Viewed" />}</td>
        </tr>
    );
}
