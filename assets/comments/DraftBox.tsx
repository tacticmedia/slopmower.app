import { KebabHorizontalIcon } from '@primer/octicons-react';
import { ActionList, ActionMenu, IconButton, Label, Link } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { lazy, Suspense, useOptimistic, useState, useTransition } from 'react';
import { Link as RouterLink, useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import { CommentBox } from './CommentBox';
import type { CommentIntent } from './commentIntent';
import type { DraftView } from './draftView';
import styles from './ThreadBox.module.css';

// A separate chunk, so the Markdown libraries stay out of the bundles that import this box.
const MarkdownBody = lazy(() => import('./MarkdownBody').then(({ MarkdownBody }) => ({ default: MarkdownBody })));

type Props = {
    draft: DraftView;
    /** False on a closed review: D3 refuses, D4 still works. */
    editable: boolean;
};

/**
 * A pending comment. The editor stays open while D3 runs and closes when it succeeds. A deleted draft disappears at
 * once and comes back when D4 fails.
 */
export function DraftBox({ draft, editable }: Props) {
    const fetcher = useFetcher<ActionResult>();
    const [writing, startWrite] = useTransition();
    const [deleted, markDeleted] = useOptimistic(false, (_current: boolean, next: boolean) => next);
    const [editing, setEditing] = useState(false);
    const [savedEdit, setSavedEdit] = useState(false);
    const { draftId } = draft;
    const error = writing ? null : (fetcher.data?.error ?? null);

    if (savedEdit && !writing) {
        setSavedEdit(false);
        if (null === error) {
            setEditing(false);
        }
    }

    if (deleted) {
        return null;
    }

    const submit = (intent: CommentIntent, optimistic: () => void): void => {
        startWrite(async () => {
            optimistic();
            await fetcher.submit(intent, { method: 'post', encType: 'application/json' });
        });
    };
    const save = (body: string, requireApproval: boolean): boolean => {
        if (writing || null === draftId) {
            return false;
        }
        setSavedEdit(true);
        submit({ intent: 'editDraft', draftId, input: null === draft.requireApproval ? { body } : { body, requireApproval } }, () => undefined);

        return true;
    };
    const remove = (): void => {
        if (!writing && null !== draftId) {
            submit({ intent: 'deleteDraft', draftId }, () => markDeleted(true));
        }
    };

    return (
        <article className={styles.thread} data-testid="draft" data-draft-id={draftId ?? undefined} aria-busy={null === draftId || writing}>
            <header className={styles.header}>
                <Label variant="attention" data-testid="pending-label">
                    Pending
                </Label>
                {null === draft.href ? (
                    <span className={styles.lines}>{draft.label}</span>
                ) : (
                    <Link as={RouterLink} to={draft.href} className={styles.lines}>
                        {draft.label}
                    </Link>
                )}
                {null !== draftId && !editing && (
                    <ActionMenu>
                        <ActionMenu.Anchor>
                            <IconButton icon={KebabHorizontalIcon} aria-label="Pending comment actions" variant="invisible" size="small" className={styles.menu} disabled={writing} />
                        </ActionMenu.Anchor>
                        <ActionMenu.Overlay>
                            <ActionList>
                                {editable && <ActionList.Item onSelect={() => setEditing(true)}>Edit</ActionList.Item>}
                                <ActionList.Item variant="danger" onSelect={remove}>
                                    Delete
                                </ActionList.Item>
                            </ActionList>
                        </ActionMenu.Overlay>
                    </ActionMenu>
                )}
            </header>
            {editing ? (
                <div className={styles.reply}>
                    <CommentBox
                        label="Edit pending comment"
                        submitLabel="Update comment"
                        onSubmit={save}
                        askForPlan={draft.requireApproval ?? undefined}
                        onCancel={() => setEditing(false)}
                        initialBody={draft.body}
                        error={error}
                        busy={writing}
                        focusOnMount
                    />
                </div>
            ) : (
                <div className={styles.comment}>
                    <Suspense fallback={<p className={styles.plain}>{draft.body}</p>}>
                        <MarkdownBody markdown={draft.body} />
                    </Suspense>
                    {null !== error && (
                        <InlineMessage variant="critical" role="alert" data-testid="draft-error">
                            {error}
                        </InlineMessage>
                    )}
                </div>
            )}
        </article>
    );
}
