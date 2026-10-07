import { Link } from '@primer/react';
import { Link as RouterLink } from 'react-router';
import styles from './Breadcrumbs.module.css';
import { directoryHref } from './fileHref';
import { pathSegments } from './pathSegments';

type Props = {
    reviewId: string;
    repositoryName: string;
    /** `""` is the root. */
    path: string;
    directory: boolean;
    /** The last name is a heading. The copy in the stuck header is not. */
    heading: boolean;
};

/**
 * `repository / src / Billing / InvoiceTotals.php`, as on GitHub: each directory links to its directory view, and a
 * directory ends with `/`. The separators are text, so the text of the trail holds the path.
 */
export function Breadcrumbs({ reviewId, repositoryName, path, directory, heading }: Props) {
    const trail = [{ name: repositoryName, path: '' }, ...pathSegments(path)];
    const last = trail.length - 1;
    const Current = heading ? 'h2' : 'strong';

    return (
        <nav aria-label="Breadcrumbs" className={styles.breadcrumbs}>
            <ol className={styles.list}>
                {trail.map((segment, index) => (
                    <li key={segment.path} className={styles.segment}>
                        {index > 0 && (
                            <span className={styles.separator} aria-hidden="true">
                                /
                            </span>
                        )}
                        {index === last ? (
                            <Current className={styles.current} aria-current="page">
                                {segment.name}
                            </Current>
                        ) : (
                            <Link as={RouterLink} to={directoryHref(reviewId, segment.path)} className={0 === index ? styles.repository : undefined}>
                                {segment.name}
                            </Link>
                        )}
                        {index === last && directory && (
                            <span className={styles.separator} aria-hidden="true">
                                /
                            </span>
                        )}
                    </li>
                ))}
            </ol>
        </nav>
    );
}
