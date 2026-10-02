import { FileIcon, FileSubmoduleIcon, FileSymlinkFileIcon } from '@primer/octicons-react';
import { TreeView } from '@primer/react';
import { type ComponentProps, useEffect, useState } from 'react';
import { useFetcher, useNavigate } from 'react-router';
import type { TreeEntry } from '../api/types/tree-entry';
import { type TreeResult, treeHref } from '../routes/reviews/treeData';
import { fileHref } from './fileHref';

type EntryProps = {
    reviewId: string;
    entry: TreeEntry;
    currentPath: string | null;
};

/**
 * The tree at the review commit. Directories list their entries through V6 when they expand.
 */
export function FileTree({ reviewId, entries, currentPath }: { reviewId: string; entries: TreeEntry[]; currentPath: string | null }) {
    return (
        <nav aria-label="Files" data-testid="file-tree">
            <TreeView aria-label="Files" truncate>
                <Entries reviewId={reviewId} entries={entries} currentPath={currentPath} />
            </TreeView>
        </nav>
    );
}

function Entries({ reviewId, entries, currentPath }: { reviewId: string; entries: TreeEntry[]; currentPath: string | null }) {
    return entries.map((entry) =>
        'directory' === entry.type ? (
            <DirectoryItem key={entry.path} reviewId={reviewId} entry={entry} currentPath={currentPath} />
        ) : (
            <LeafItem key={entry.path} reviewId={reviewId} entry={entry} currentPath={currentPath} />
        ),
    );
}

function DirectoryItem({ reviewId, entry, currentPath }: EntryProps) {
    const { load, state, data } = useFetcher<TreeResult>();
    const [expanded, setExpanded] = useState(() => null !== currentPath && currentPath.startsWith(`${entry.path}/`));
    const href = treeHref(reviewId, entry.path);

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
            <TreeView.SubTree state={subTreeState(state, data)}>
                {undefined !== data && 'items' in data && <Entries reviewId={reviewId} entries={data.items} currentPath={currentPath} />}
            </TreeView.SubTree>
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
function LeafItem({ reviewId, entry, currentPath }: EntryProps) {
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
        </TreeView.Item>
    );
}

function subTreeState(fetcherState: string, data: TreeResult | undefined): ComponentProps<typeof TreeView.SubTree>['state'] {
    if (undefined === data) {
        return 'idle' === fetcherState ? 'initial' : 'loading';
    }

    return 'error' in data ? 'error' : 'done';
}
