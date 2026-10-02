import { useMemo } from 'react';
import { type LoaderFunctionArgs, Outlet, useLoaderData, useParams, useRouteLoaderData } from 'react-router';
import type { TreeListing } from '../../api/types/lists';
import { FileTree } from '../../review/FileTree';
import { encodedRouteParam } from '../encodedRouteParam';
import styles from './CodeLayout.module.css';
import type { ReviewData } from './reviewData';
import { listDirectory } from './treeData';

export function codeLayoutLoader({ request, params }: LoaderFunctionArgs): Promise<TreeListing> {
    return listDirectory(encodedRouteParam(params, 'reviewId'), '', request.signal);
}

/**
 * The file tree beside the file route. It stays mounted while files change, so expanded directories stay open.
 */
export function CodeLayout() {
    const { reviewId, items } = useLoaderData<TreeListing>();
    const viewedPaths = useRouteLoaderData<ReviewData>('review')?.viewedPaths;
    const viewed = useMemo(() => new Set(viewedPaths), [viewedPaths]);
    const currentPath = useParams()['*'] || null;

    return (
        <div className={styles.layout}>
            <aside className={styles.tree}>
                <FileTree reviewId={reviewId} entries={items} currentPath={currentPath} viewed={viewed} />
            </aside>
            <Outlet />
        </div>
    );
}

export function NoFileSelected() {
    return <p className={styles.hint}>Select a file in the tree.</p>;
}
