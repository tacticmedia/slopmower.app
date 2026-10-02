import { useCallback, useOptimistic, useRef, useState, useTransition } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import type { Actor } from '../api/types/actor';
import type { LineRange } from '../code/lineHash';
import { anchorInput } from '../code/rangeSelection';
import type { CommentIntent } from './commentIntent';
import { type DraftView, pendingDraft } from './draftView';
import { pendingThread, type ThreadView } from './threadView';

export type Composer = {
    /** A new key remounts the comment box with `initialBody`. */
    key: number;
    /** Null for a file comment. */
    range: LineRange | null;
    initialBody: string;
    error: string | null;
};

/** `single` opens a request at once with Q2, `draft` adds a pending comment with D2. */
export type SubmitMode = 'single' | 'draft';

type Options = {
    path: string;
    threads: ThreadView[];
    drafts: DraftView[];
    caller: Actor;
    /** Gap G1: while a selection exists, the "+" stays on its last line. */
    clearSelection(): void;
};

type Sent = { path: string; range: LineRange | null; body: string };

/**
 * The one comment box of a file, Q2, and D2. The new thread or pending comment shows at once; when the write fails,
 * the box opens again with the text and the error.
 */
export function useFileComments({ path, threads, drafts, caller, clearSelection }: Options) {
    const fetcher = useFetcher<ActionResult>();
    const [sending, startSending] = useTransition();
    const [shownThreads, addThread] = useOptimistic(threads, (current: ThreadView[], added: ThreadView) => [...current, added]);
    const [shownDrafts, addDraft] = useOptimistic(drafts, (current: DraftView[], added: DraftView) => [...current, added]);
    const opened = useRef(0);
    const [composer, setComposer] = useState<(Composer & { path: string }) | null>(null);
    const [sent, setSent] = useState<Sent | null>(null);

    if (null !== sent && !sending) {
        setSent(null);
        const error = fetcher.data?.error;
        if (undefined !== error) {
            opened.current += 1;
            setComposer({ key: opened.current, path: sent.path, range: sent.range, initialBody: sent.body, error });
        }
    }

    const shownComposer = composer?.path === path ? composer : null;

    const open = useCallback(
        (range: LineRange | null): void => {
            if (!sending) {
                opened.current += 1;
                setComposer({ key: opened.current, path, range, initialBody: '', error: null });
            }
        },
        [path, sending],
    );

    const cancel = useCallback((): void => {
        setComposer(null);
        clearSelection();
    }, [clearSelection]);

    const submit = useCallback(
        (body: string, mode: SubmitMode): boolean => {
            if (sending || null === shownComposer) {
                return false;
            }
            const { range } = shownComposer;
            setComposer(null);
            clearSelection();
            setSent({ path, range, body });
            const anchor = anchorInput(path, range);
            const intent: CommentIntent = 'draft' === mode ? { intent: 'draft', input: { kind: 'new_request', anchor, body } } : { intent: 'open', input: { anchor, body } };
            startSending(async () => {
                if ('draft' === mode) {
                    addDraft(pendingDraft(range, body));
                } else {
                    addThread(pendingThread(range, body, caller));
                }
                await fetcher.submit(intent, { method: 'post', encType: 'application/json' });
            });

            return true;
        },
        [sending, shownComposer, path, caller, clearSelection, addThread, addDraft, fetcher.submit],
    );

    return { threads: shownThreads, drafts: shownDrafts, composer: shownComposer, open, cancel, submit };
}
