import { CheckIcon, FileIcon, FileSubmoduleIcon, FileSymlinkFileIcon, InfoIcon } from '@primer/octicons-react';
import { TreeView } from '@primer/react';
import { type ComponentProps, type KeyboardEvent, type MouseEvent, useEffect, useState } from 'react';
import { useFetcher, useNavigate } from 'react-router';
import type { TreeEntry } from '../api/types/tree-entry';
import { TRUNCATED_TEXT, type TreeResult, treeHref } from '../routes/reviews/treeData';
import { directoryHref, fileHref } from './fileHref';
import styles from './FileTree.module.css';

type TreeProps = {
    reviewId: string;
    entries: TreeEntry[];
    /** The listing has more entries than it shows. */
    truncated: boolean;
    currentPath: string | null;
    /** The paths that the caller marked as viewed. */
    viewed: ReadonlySet<string>;
};

type EntryProps = Omit<TreeProps, 'entries' | 'truncated'> & { entry: TreeEntry };

/**
 * The tree at the review commit. Directories list their entries through V6 when they expand. The open file or
 * directory and the directories above it expand, and the pane scrolls it into view.
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

function Entries({ entries, truncated, ...props }: TreeProps) {
    return (
        <>
            {entries.map((entry) =>
                'directory' === entry.type ? <DirectoryItem key={entry.path} entry={entry} {...props} /> : <LeafItem key={entry.path} entry={entry} {...props} />,
            )}
            {truncated && (
                <TreeView.Item id={itemId(`${entries[0]?.path ?? ''}#truncated`)} data-testid="tree-truncated">
                    <TreeView.LeadingVisual>
                        <InfoIcon />
                    </TreeView.LeadingVisual>
                    {TRUNCATED_TEXT}
                </TreeView.Item>
            )}
        </>
    );
}

function DirectoryItem({ entry, ...props }: EntryProps) {
    const { reviewId, currentPath } = props;
    const navigate = useNavigate();
    const { load, state, data } = useFetcher<TreeResult>();
    const current = entry.path === currentPath;
    const holdsCurrent = current || (null !== currentPath && currentPath.startsWith(`${entry.path}/`));
    const [expanded, setExpanded] = useState(holdsCurrent);
    const [followedPath, setFollowedPath] = useState(currentPath);
    const href = treeHref(reviewId, entry.path);

    // A newly opened file or directory expands its ancestors and itself. Other directories keep the state that the user gave them.
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

    useRevealWhenCurrent(entry.path, current);

    // The label opens the directory, the chevron only toggles it.
    return (
        <TreeView.Item
            id={itemId(entry.path)}
            data-path={entry.path}
            current={current}
            expanded={expanded}
            onExpandedChange={setExpanded}
            onSelect={() => void navigate(directoryHref(reviewId, entry.path))}
        >
            <TreeView.LeadingVisual>
                <TreeView.DirectoryIcon />
            </TreeView.LeadingVisual>
            {entry.name}
            <TreeView.SubTree state={subTreeState(state, data)}>{undefined !== data && 'items' in data && <Entries entries={data.items} truncated={null !== data.nextCursor} {...props} />}</TreeView.SubTree>
            {expanded && undefined !== data && 'error' in data && (
                <TreeView.ErrorDialog onRetry={() => void load(href)} onDismiss={() => setExpanded(false)}>
                    {data.error}
                </TreeView.ErrorDialog>
            )}
        </TreeView.Item>
    );
}

/**
 * A file is a link, so a modified click, a middle click, and the context menu open it in a new tab. A directory is no
 * link: its item element holds its subtree. V7 refuses submodules, so they are not links.
 */
function LeafItem({ reviewId, entry, currentPath, viewed }: EntryProps) {
    const navigate = useNavigate();
    const current = entry.path === currentPath;
    useRevealWhenCurrent(entry.path, current);
    const Icon = 'symlink' === entry.type ? FileSymlinkFileIcon : 'submodule' === entry.type ? FileSubmoduleIcon : FileIcon;
    const href = 'submodule' === entry.type ? null : fileHref(reviewId, entry.path);
    const open = (event: MouseEvent<HTMLElement> | KeyboardEvent<HTMLElement>): void => {
        if (null === href || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || ('button' in event && 0 !== event.button)) {
            return;
        }
        event.preventDefault();
        void navigate(href);
    };

    return (
        <TreeView.Item
            id={itemId(entry.path)}
            data-path={entry.path}
            current={current}
            {...(null === href ? {} : { as: 'a', href, onSelect: open, className: styles.file })}
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

function itemId(path: string): string {
    return `file-tree:${path}`;
}

/**
 * Scrolls the tree, not the window, so that the current item shows. An item of a directory that loads later reveals
 * itself when it mounts.
 */
function useRevealWhenCurrent(path: string, current: boolean): void {
    useEffect(() => {
        const item = current ? document.getElementById(itemId(path)) : null;
        const scroller = null === item ? null : scrollingAncestor(item);
        if (null === item || null === scroller) {
            return;
        }
        const pane = scroller.getBoundingClientRect();
        const visibleTop = Math.max(pane.top, 0);
        const visibleBottom = Math.min(pane.bottom, window.innerHeight);
        // The row, without the subtree of an expanded directory.
        const row = (item.firstElementChild ?? item).getBoundingClientRect();
        if (row.top < visibleTop || row.bottom > visibleBottom) {
            scroller.scrollTop += row.top - (visibleTop + visibleBottom - row.height) / 2;
        }
    }, [path, current]);
}

function scrollingAncestor(element: HTMLElement): HTMLElement | null {
    for (let node = element.parentElement; null !== node; node = node.parentElement) {
        const { overflowY } = getComputedStyle(node);
        if (('auto' === overflowY || 'scroll' === overflowY) && node.scrollHeight > node.clientHeight) {
            return node;
        }
    }

    return null;
}
