import { SidebarCollapseIcon, SidebarExpandIcon } from '@primer/octicons-react';
import { IconButton, PageLayout } from '@primer/react';
import { useMemo } from 'react';
import { type LoaderFunctionArgs, Outlet, useLoaderData, useOutletContext, useParams, useRouteLoaderData } from 'react-router';
import type { TreeListing } from '../../api/types/lists';
import { usePreference } from '../../layout/usePreference';
import { FileTree } from '../../review/FileTree';
import { encodedRouteParam } from '../encodedRouteParam';
import styles from './CodeLayout.module.css';
import type { ReviewData } from './reviewData';
import { listDirectory } from './treeData';

/** The outlet context of the file and directory routes. */
export type CodeBrowser = { treeCollapsed: boolean; setTreeCollapsed(collapsed: boolean): void };

export function useCodeBrowser(): CodeBrowser {
    return useOutletContext<CodeBrowser>();
}

const TREE_WIDTH = { min: '256px', default: '320px', max: '640px' } as const;

export function codeLayoutLoader({ request, params }: LoaderFunctionArgs): Promise<TreeListing> {
    return listDirectory(encodedRouteParam(params, 'reviewId'), '', request.signal);
}

/**
 * The file tree beside the file and directory routes, as on GitHub: the window scrolls, and the tree pane sticks with
 * the window height. It stays mounted while files change, so expanded directories stay open.
 */
export function CodeLayout() {
    const { reviewId, items } = useLoaderData<TreeListing>();
    const viewedPaths = useRouteLoaderData<ReviewData>('review')?.viewedPaths;
    const viewed = useMemo(() => new Set(viewedPaths), [viewedPaths]);
    const currentPath = useParams()['*'] || null;
    const [treeCollapsed, setTreeCollapsed] = usePreference('treeCollapsed');
    const codeBrowser = useMemo<CodeBrowser>(() => ({ treeCollapsed, setTreeCollapsed }), [treeCollapsed, setTreeCollapsed]);

    return (
        <PageLayout containerWidth="full" padding="none" rowGap="none" columnGap="none" className={styles.layout}>
            <PageLayout.Pane
                position="start"
                sticky
                resizable
                widthStorageKey="slopmower.tree-width"
                width={TREE_WIDTH}
                hidden={treeCollapsed}
                aria-label="File tree"
                className={styles.pane}
            >
                <div className={styles.paneHeader}>
                    <IconButton icon={SidebarExpandIcon} aria-label="Collapse file tree" variant="invisible" onClick={() => setTreeCollapsed(true)} />
                    <h2 className={styles.paneTitle}>Files</h2>
                </div>
                <div className={styles.tree}>
                    <FileTree reviewId={reviewId} entries={items} currentPath={currentPath} viewed={viewed} />
                </div>
            </PageLayout.Pane>
            <PageLayout.Content as="div" padding="condensed">
                <Outlet context={codeBrowser} />
            </PageLayout.Content>
        </PageLayout>
    );
}

/** Shows the tree again. Only rendered while the tree is collapsed. */
export function ExpandTreeButton() {
    const { treeCollapsed, setTreeCollapsed } = useCodeBrowser();

    return treeCollapsed ? <IconButton icon={SidebarCollapseIcon} aria-label="Expand file tree" variant="invisible" onClick={() => setTreeCollapsed(false)} /> : null;
}
