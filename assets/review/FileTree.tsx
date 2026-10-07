import { CheckIcon, FileIcon, FileSubmoduleIcon, FileSymlinkFileIcon } from '@primer/octicons-react';
import { TreeView } from '@primer/react';
import { type ComponentProps, useEffect, useState } from 'react';
import { useFetcher, useNavigate } from 'react-router';
import type { TreeEntry } from '../api/types/tree-entry';
import { type TreeResult, treeHref } from '../routes/reviews/treeData';
import { fileHref } from './fileHref';

type TreeProps = {
    reviewId: string;
    entries: TreeEntry[];
    currentPath: string | null;
    /** The paths that the caller marked as viewed. */
    viewed: ReadonlySet<string>;
};

type EntryProps = Omit<TreeProps, 'entries'> & { entry: TreeEntry };

/**
 * The tree at the review commit. Directories list their entries through V6 when they expand, and the directories
 * above the open file expand.
 */
export function FileTree(props: TreeProps) {
    return (
        <nav aria-label="Files" data-testid="file-tree">
            <TreeView aria-label="Files" truncate>
                <Entries {...props} />
            </TreeView>
        </nav>
    );
}

function Entries({ entries, ...props }: TreeProps) {
    return entries.map((entry) =>
        'directory' === entry.type ? <DirectoryItem key={entry.path} entry={entry} {...props} /> : <LeafItem key={entry.path} entry={entry} {...props} />,
    );
}

function DirectoryItem({ entry, ...props }: EntryProps) {
    const { reviewId, currentPath } = props;
    const { load, state, data } = useFetcher<TreeResult>();
    const holdsCurrent = null !== currentPath && currentPath.startsWith(`${entry.path}/`);
    const [expanded, setExpanded] = useState(holdsCurrent);
    const [followedPath, setFollowedPath] = useState(currentPath);
    const href = treeHref(reviewId, entry.path);

    // A newly opened file expands its ancestors. Other directories keep the state that the user gave them.
    if (followedPath !== currentPath) {
        setFollowedPath(currentPath);
        if (holdsCurrent) {
            setExpanded(true);
        }
    }

    useEffect(() => {
        if (expanded && 'idle' === state && undefined === data) {
            void load(href);
        }
    }, [expanded, state, data, load, href]);

    return (
        <TreeView.Item id={`file-tree:${entry.path}`} data-path={entry.path} expanded={expanded} onExpandedChange={setExpanded}>
            <TreeView.LeadingVisual>
                <TreeView.DirectoryIcon />
            </TreeView.LeadingVisual>
            {entry.name}
            <TreeView.SubTree state={subTreeState(state, data)}>{undefined !== data && 'items' in data && <Entries entries={data.items} {...props} />}</TreeView.SubTree>
            {expanded && undefined !== data && 'error' in data && (
                <TreeView.ErrorDialog onRetry={() => void load(href)} onDismiss={() => setExpanded(false)}>
                    {data.error}
                </TreeView.ErrorDialog>
            )}
        </TreeView.Item>
    );
}

/**
 * V7 refuses submodules, so they are not links.
 */
function LeafItem({ reviewId, entry, currentPath, viewed }: EntryProps) {
    const navigate = useNavigate();
    const Icon = 'symlink' === entry.type ? FileSymlinkFileIcon : 'submodule' === entry.type ? FileSubmoduleIcon : FileIcon;

    return (
        <TreeView.Item
            id={`file-tree:${entry.path}`}
            data-path={entry.path}
            current={entry.path === currentPath}
            onSelect={'submodule' === entry.type ? undefined : () => void navigate(fileHref(reviewId, entry.path))}
        >
            <TreeView.LeadingVisual>
                <Icon />
            </TreeView.LeadingVisual>
            {entry.name}
            {viewed.has(entry.path) && (
                <TreeView.TrailingVisual label="Viewed">
                    <span data-testid="viewed-mark">
                        <CheckIcon />
                    </span>
                </TreeView.TrailingVisual>
            )}
        </TreeView.Item>
    );
}

function subTreeState(fetcherState: string, data: TreeResult | undefined): ComponentProps<typeof TreeView.SubTree>['state'] {
    if (undefined === data) {
        return 'idle' === fetcherState ? 'initial' : 'loading';
    }

    return 'error' in data ? 'error' : 'done';
}
