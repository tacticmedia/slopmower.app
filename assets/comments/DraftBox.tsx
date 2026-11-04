import { KebabHorizontalIcon } from '@primer/octicons-react';
import { ActionList, ActionMenu, IconButton, Label, Link } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { lazy, Suspense, useContext, useEffect, useOptimistic, useRef, useState, useTransition } from 'react';
import { Link as RouterLink, useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import { readSessionDraft } from '../layout/useSessionDraft';
import { RequestTypeLabel } from '../requests/RequestTypeLabel';
import { CommentBox, type CommentText } from './CommentBox';
import type { CommentIntent } from './commentIntent';
import { DraftLinesContext } from './draftLines';
import type { DraftView } from './draftView';
import { LineRangeEditor, type LineRangeEditorHandle } from './LineRangeEditor';
import styles from './ThreadBox.module.css';

// A separate chunk, so the Markdown libraries stay out of the bundles that import this box.
const MarkdownBody = lazy(() => import('./MarkdownBody').then(({ MarkdownBody }) => ({ default: MarkdownBody })));

type Props = {
    draft: DraftView;
    /** False on a closed review: D3 refuses, D4 still works. */
    editable: boolean;
};

/**
 * A pending comment. The editor stays open while D3 runs and closes when it succeeds. A deleted or sent draft
 * disappears at once and comes back when D4 or D5 fails. In the file view, the editor highlights the lines and can
 * move them (D115).
 */
export function DraftBox({ draft, editable }: Props) {
    const fetcher = useFetcher<ActionResult>();
    const [writing, startWrite] = useTransition();
    const [deleted, markDeleted] = useOptimistic(false, (_current: boolean, next: boolean) => next);
    const { draftId } = draft;
    const editKey = null === draftId ? null : `draft:${draftId}:edit`;
    // An edit that a reload kept opens the editor again.
    const [editing, setEditing] = useState(() => editable && null !== editKey && null !== readSessionDraft(editKey));
    const [savedEdit, setSavedEdit] = useState(false);
    const lines = useContext(DraftLinesContext);
    const [range, setRange] = useState(draft.range);
    const [rangeError, setRangeError] = useState<string | null>(null);
    const rangeEditor = useRef<LineRangeEditorHandle>(null);
    const newRequest = null !== draft.requireApproval;
    const error = writing ? null : (rangeError ?? fetcher.data?.error ?? null);
    const highlight = lines?.highlight;
    const { key } = draft;

    useEffect(() => {
        if (editing && null !== range && undefined !== highlight) {
            highlight(key, range);

            return () => highlight(key, null);
        }
    }, [editing, range, highlight, key]);

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
    const edit = (): void => {
        setRange(draft.range);
        setRangeError(null);
        setEditing(true);
    };
    const save = (text: CommentText): boolean => {
        if (writing || null === draftId || null === editKey) {
            return false;
        }
        const saved = null === rangeEditor.current ? range : rangeEditor.current.apply();
        if (null !== range && null === saved) {
            return false;
        }
        const moved = null !== lines && null !== saved && null !== draft.range && (saved.start !== draft.range.start || saved.end !== draft.range.end);
        setSavedEdit(true);
        submit({ intent: 'editDraft', draftId, input: newRequest ? { ...text, ...(moved ? { anchor: lines.anchor(saved) } : {}) } : { body: text.body }, draftKey: editKey }, () => undefined);

        return true;
    };
    const remove = (intent: 'deleteDraft' | 'sendDraft'): void => {
        if (!writing && null !== draftId) {
            submit({ intent, draftId }, () => markDeleted(true));
        }
    };

    return (
        <article className={styles.thread} data-testid="draft" data-draft-id={draftId ?? undefined} aria-busy={null === draftId || writing}>
            <header className={styles.header}>
                <Label variant="attention" data-testid="pending-label">
                    Pending
                </Label>
                <RequestTypeLabel type={draft.type} />
                {null !== draft.group && <Label data-testid="draft-group">Group: {draft.group}</Label>}
                {editing && null !== lines && null !== range ? (
                    <LineRangeEditor ref={rangeEditor} range={range} lineCount={lines.lineCount} onChange={setRange} onError={setRangeError} disabled={writing} />
                ) : null === draft.href ? (
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
                                {editable && <ActionList.Item onSelect={() => remove('sendDraft')}>Send now</ActionList.Item>}
                                {editable && <ActionList.Item onSelect={edit}>Edit</ActionList.Item>}
                                <ActionList.Item variant="danger" onSelect={() => remove('deleteDraft')}>
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
                        draftKey={editKey}
                        onSubmit={save}
                        askForPlan={draft.requireApproval ?? undefined}
                        initialType={draft.type}
                        initialGroup={draft.group ?? ''}
                        titled={newRequest}
                        onCancel={() => {
                            setRangeError(null);
                            setEditing(false);
                        }}
                        initialTitle={draft.title ?? ''}
                        initialBody={draft.body}
                        initialTextSaved
                        error={error}
                        busy={writing}
                        focusOnMount
                    />
                </div>
            ) : (
                <div className={styles.comment}>
                    {null !== draft.title && (
                        <p className={styles.title} data-testid="draft-title">
                            {draft.title}
                        </p>
                    )}
                    <Suspense fallback={<p className={styles.plain} data-markdown-pending="">{draft.body}</p>}>
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
