import { isEditableElement } from '@primer/behaviors/utils';
import { CodeIcon, FileIcon, SearchIcon } from '@primer/octicons-react';
import { Button, Dialog, type SelectPanelItemInput } from '@primer/react';
import { FilteredActionList, FilteredActionListLoadingTypes, KeybindingHint } from '@primer/react/experimental';
import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router';
import type { IndexState } from '../api/types/common';
import type { SearchHit } from '../api/types/search-hit';
import { formatLineHash, lineRange } from '../code/lineHash';
import { symbolKindLabel } from '../code/symbolMatch';
import { ariaKeyShortcuts, hintKeys, isPressed, type ModShortcut } from '../layout/modShortcut';
import { type SearchResult, useSearch } from '../routes/reviews/searchData';
import { fileHref } from './fileHref';
import styles from './QuickOpen.module.css';

type Props = { reviewId: string; indexState: IndexState };

const SHORTCUT: ModShortcut = { key: 'K', shift: false };

/**
 * The button and the shortcut open the dialog. The shortcut is off in a text field outside the dialog, where the
 * platform may bind it. The header stays mounted when the page scrolls it away, so the shortcut works there too.
 */
export function QuickOpen({ reviewId, indexState }: Props) {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const toggle = (event: KeyboardEvent): void => {
            // The dialog is modal: while it is open, the focused field is its own.
            if (isPressed(SHORTCUT, event) && (open || !isEditableElement(event.composedPath()[0] ?? null))) {
                event.preventDefault();
                setOpen(!open);
            }
        };
        window.addEventListener('keydown', toggle);

        return () => window.removeEventListener('keydown', toggle);
    }, [open]);

    return (
        <>
            <Button leadingVisual={SearchIcon} trailingVisual={<KeybindingHint keys={hintKeys(SHORTCUT)} />} aria-keyshortcuts={ariaKeyShortcuts(SHORTCUT)} onClick={() => setOpen(true)} data-tour="quick-open">
                Quick open
            </Button>
            {open && <QuickOpenDialog reviewId={reviewId} indexState={indexState} onClose={() => setOpen(false)} />}
        </>
    );
}

/** The focus trap of the dialog gives the focus back to the element that had it before. */
function QuickOpenDialog({ reviewId, indexState, onClose }: Props & { onClose(): void }) {
    const [query, setQuery] = useState('');
    const input = useRef<HTMLInputElement>(null);
    const navigate = useNavigate();
    const { result, loading } = useSearch(reviewId, query.trim());
    const choose = (href: string): void => {
        onClose();
        void navigate(href);
    };
    const items = null === result || 'error' in result ? [] : result.items.flatMap((hit) => hitItem(reviewId, hit, choose));
    const partial = null !== result && 'partial' in result && result.partial;

    return (
        <Dialog title="Quick open" width="xlarge" onClose={onClose} initialFocusRef={input}>
            <FilteredActionList
                inputRef={input}
                placeholderText="File or symbol name"
                filterValue={query}
                onFilterChange={setQuery}
                items={items}
                loading={loading}
                loadingType={FilteredActionListLoadingTypes.input}
                message={emptyMessage(query.trim(), result)}
                className={styles.list}
            />
            <p role="status" className={styles.status}>
                {partial && ('failed' === indexState ? 'Index failed: hits may be missing' : 'Index in progress')}
            </p>
        </Dialog>
    );
}

/** A hit of a kind that this client does not know gives no item. */
function hitItem(reviewId: string, hit: SearchHit, choose: (href: string) => void): SelectPanelItemInput[] {
    switch (hit.kind) {
        case 'file': {
            const href = fileHref(reviewId, hit.path);

            return [{ id: href, text: hit.path, leadingVisual: FileIcon, onAction: () => choose(href) }];
        }
        case 'symbol': {
            const href = `${fileHref(reviewId, hit.path)}${formatLineHash(lineRange(hit.declarationStartLine, hit.declarationStartLine))}`;
            const description = `${symbolKindLabel({ kind: hit.symbolKind })} in ${hit.path}, line ${hit.declarationStartLine}`;

            return [{ id: `${href} ${hit.fqn}`, text: hit.fqn, description, descriptionVariant: 'block', leadingVisual: CodeIcon, onAction: () => choose(href) }];
        }
        default:
            return [];
    }
}

/** Shown in place of the list: a hint, an error, or no hits. Undefined while hits exist or load. */
function emptyMessage(query: string, result: SearchResult | null) {
    let text: string | null = null;

    if ('' === query) {
        text = 'Type a part of a file path or of a symbol name.';
    } else if (null !== result && 'error' in result) {
        text = result.error;
    } else if (null !== result && 0 === result.items.length) {
        text = 'No file or symbol matches.';
    }

    return null === text ? undefined : <p className={styles.message}>{text}</p>;
}
