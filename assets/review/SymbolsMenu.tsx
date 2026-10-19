import { TriangleDownIcon } from '@primer/octicons-react';
import { Button, SelectPanel, Tooltip } from '@primer/react';
import { useMemo, useState } from 'react';
import type { Symbol as CodeSymbol } from '../api/types/symbol';
import { duplicateFqns, symbolKindLabel, symbolShortName } from '../code/symbolMatch';
import { MAX_SHOWN_SYMBOLS, symbolItems, TOP_LEVEL_GROUP } from './symbolItems';

type Props = {
    /** Null while the symbols load and when they failed to load. */
    symbols: readonly CodeSymbol[] | null;
    failed: boolean;
    onChoose(symbol: CodeSymbol): void;
};

/** The menu runs a command and keeps no selection. Each item's `onAction` chooses its symbol. */
function ignoreSelection(): void {}

/**
 * Decision D31. Primer's filterable menu is the SelectPanel; an ActionMenu takes no filter field. Without symbols, the
 * button says why in a tooltip: `aria-disabled` keeps it focusable and hoverable, `disabled` would not.
 */
export function SymbolsMenu({ symbols, failed, onChoose }: Props) {
    const [open, setOpen] = useState(false);
    const [filter, setFilter] = useState('');
    const { groups, shownCount, matchCount } = useMemo(() => symbolItems(symbols ?? [], filter), [symbols, filter]);
    const duplicates = useMemo(() => duplicateFqns(symbols ?? []), [symbols]);
    const items = useMemo(
        () =>
            groups.flatMap(({ id, symbols: grouped }) =>
                grouped.map((symbol) => {
                    const ambiguous = duplicates.has(symbol.fqn);

                    return {
                        groupId: id,
                        text: symbolShortName(symbol),
                        description: ambiguous ? `${symbolKindLabel(symbol)}, the FQN is not unique` : symbolKindLabel(symbol),
                        descriptionVariant: 'inline' as const,
                        disabled: ambiguous,
                        onAction: () => onChoose(symbol),
                    };
                }),
            ),
        [groups, duplicates, onChoose],
    );
    const groupMetadata = useMemo(
        () => groups.map(({ id }) => ({ groupId: id, header: { title: TOP_LEVEL_GROUP === id ? 'Top level' : id, variant: 'subtle' as const } })),
        [groups],
    );

    const changeOpen = (next: boolean): void => {
        setOpen(next);
        if (!next) {
            setFilter('');
        }
    };
    const unavailable = failed ? 'Symbols could not be loaded' : 0 === symbols?.length ? 'No symbols in this file' : null;

    if (null !== unavailable) {
        return (
            <Tooltip text={unavailable} type="description">
                <Button size="small" trailingAction={TriangleDownIcon} aria-disabled data-tour="symbols">
                    Symbols
                </Button>
            </Tooltip>
        );
    }

    return (
        <SelectPanel
            title="Symbols"
            renderAnchor={(anchorProps) => (
                <Button {...anchorProps} size="small" trailingAction={TriangleDownIcon} disabled={null === symbols} data-tour="symbols">
                    Symbols
                </Button>
            )}
            open={open}
            onOpenChange={changeOpen}
            placeholderText="Filter symbols"
            inputLabel="Filter symbols"
            filterValue={filter}
            onFilterChange={setFilter}
            loading={false}
            items={items}
            groupMetadata={groupMetadata}
            selected={undefined}
            onSelectedChange={ignoreSelection}
            notice={matchCount > MAX_SHOWN_SYMBOLS ? { text: `Showing ${shownCount} of ${matchCount} symbols. Type to filter.`, variant: 'info' } : undefined}
            message={0 === matchCount ? { title: 'No symbol matches', body: 'The filter looks for a part of the name.', variant: 'empty' } : undefined}
            width="large"
        />
    );
}
