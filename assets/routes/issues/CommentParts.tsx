import { ActionList, ActionMenu, Label, type LabelProps, Link } from '@primer/react';
import { Link as RouterLink, useSearchParams } from 'react-router';
import type { Actor } from '../../api/types/actor';
import { COMMENT_LABELS, type CommentLabel } from '../../api/types/common';
import { labelFilter } from './issueData';

export const COMMENT_LABEL_TEXT: Record<CommentLabel, string> = {
    comment: 'Comment',
    decision: 'Decision',
    question: 'Question',
    finding: 'Finding',
    deviation: 'Deviation',
    scope_creep: 'Scope creep',
    papercut: 'Papercut',
    review: 'Review',
};

/** A colour for the labels that a reader acts on. The others are gray. */
const VARIANTS: Record<CommentLabel, NonNullable<LabelProps['variant']>> = {
    comment: 'secondary',
    decision: 'done',
    question: 'severe',
    finding: 'secondary',
    deviation: 'attention',
    scope_creep: 'secondary',
    papercut: 'secondary',
    review: 'secondary',
};

const FILTER_OPTIONS: readonly (CommentLabel | null)[] = [null, ...COMMENT_LABELS];

/** After the name of the actor, with its own space. `comment` is the default and shows no badge. */
export function CommentLabelBadge({ label }: { label: CommentLabel | null }) {
    if (null === label || 'comment' === label) {
        return null;
    }

    return (
        <>
            {' '}
            <Label variant={VARIANTS[label]} data-testid="comment-label">
                {COMMENT_LABEL_TEXT[label]}
            </Label>
        </>
    );
}

/** Sets the URL search parameter `label`, which {@link labelFilter} reads. */
export function CommentLabelFilter() {
    const [searchParams, setSearchParams] = useSearchParams();
    const filter = labelFilter(searchParams);

    return (
        <ActionMenu>
            <ActionMenu.Button size="small">Label: {null === filter ? 'All' : COMMENT_LABEL_TEXT[filter]}</ActionMenu.Button>
            <ActionMenu.Overlay>
                <ActionList selectionVariant="single">
                    {FILTER_OPTIONS.map((label) => (
                        <ActionList.Item key={label ?? 'all'} selected={label === filter} onSelect={() => setSearchParams(null === label ? {} : { label })}>
                            {null === label ? 'All' : COMMENT_LABEL_TEXT[label]}
                        </ActionList.Item>
                    ))}
                </ActionList>
            </ActionMenu.Overlay>
        </ActionMenu>
    );
}

export function NoCommentWithLabel() {
    return (
        <>
            No comment has this label.{' '}
            <Link as={RouterLink} to={{ search: '' }}>
                Clear the filter
            </Link>
        </>
    );
}

export function ActorName({ actor }: { actor: Actor }) {
    return (
        <>
            <strong>{actor.name}</strong>
            {'agent' === actor.kind && (
                <>
                    {' '}
                    <Label variant="secondary">Agent</Label>
                </>
            )}
        </>
    );
}
