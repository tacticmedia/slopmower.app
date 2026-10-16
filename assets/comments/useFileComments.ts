import { useCallback, useEffect, useOptimistic, useRef, useState, useTransition } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import type { Actor } from '../api/types/actor';
import type { Symbol as CodeSymbol } from '../api/types/symbol';
import type { LineRange } from '../code/lineHash';
import { anchorInput } from '../code/rangeSelection';
import type { CommentText } from './CommentBox';
import type { CommentIntent } from './commentIntent';
import { type DraftView, pendingDraft } from './draftView';
import { pendingThread, type ThreadView } from './threadView';

export type Composer = {
    /** A new key remounts the comment box with `initialBody`. */
    key: number;
    /** Null for a file comment. */
    range: LineRange | null;
    /** With `range`, the symbol that the comment anchors to. */
    symbol: CodeSymbol | null;
    initialTitle: string;
    initialBody: string;
    /** The "Ask for a plan first" checkbox. */
    requireApproval: boolean;
    error: string | null;
};

/** `single` opens a request at once with Q2, `draft` adds a pending comment with D2. */
export type SubmitMode = 'single' | 'draft';

type Options = {
    path: string;
    threads: ThreadView[];
    drafts: DraftView[];
    caller: Actor;
    /** Sets the line selection. Gap G1: while a selection exists, the "+" stays on its last line. */
    select(range: LineRange | null): void;
};

type Sent = { path: string; range: LineRange | null; symbol: CodeSymbol | null; text: CommentText };

/**
 * The one comment box of a file, Q2, and D2. The new thread or pending comment shows at once; when the write fails,
 * the box opens again with the text, the error, and the line selection.
 */
export function useFileComments({ path, threads, drafts, caller, select }: Options) {
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
            const { path: sentPath, range, symbol, text } = sent;
            opened.current += 1;
            setComposer({ key: opened.current, path: sentPath, range, symbol, initialTitle: text.title ?? '', initialBody: text.body, requireApproval: text.requireApproval, error });
        }
    }

    const shownComposer = composer?.path === path ? composer : null;
    const failedRange = null === shownComposer || null === shownComposer.error ? null : shownComposer.range;

    useEffect(() => {
        if (null !== failedRange) {
            select(failedRange);
        }
    }, [failedRange, select]);

    const open = useCallback(
        (range: LineRange | null, symbol: CodeSymbol | null = null): void => {
            if (!sending) {
                opened.current += 1;
                setComposer({ key: opened.current, path, range, symbol, initialTitle: '', initialBody: '', requireApproval: true, error: null });
            }
        },
        [path, sending],
    );

    const cancel = useCallback((): void => {
        setComposer(null);
        select(null);
    }, [select]);

    const submit = useCallback(
        (text: CommentText, mode: SubmitMode): boolean => {
            if (sending || null === shownComposer) {
                return false;
            }
            const { range, symbol } = shownComposer;
            setComposer(null);
            select(null);
            setSent({ path, range, symbol, text });
            const anchor = anchorInput(path, range, symbol?.fqn ?? null);
            const intent: CommentIntent = 'draft' === mode ? { intent: 'draft', input: { kind: 'new_request', anchor, ...text } } : { intent: 'open', input: { anchor, ...text } };
            startSending(async () => {
                if ('draft' === mode) {
                    addDraft(pendingDraft(range, text));
                } else {
                    addThread(pendingThread(range, symbol?.fqn ?? null, text.title ?? null, text.body, caller));
                }
                await fetcher.submit(intent, { method: 'post', encType: 'application/json' });
            });

            return true;
        },
        [sending, shownComposer, path, caller, select, addThread, addDraft, fetcher.submit],
    );

    return { threads: shownThreads, drafts: shownDrafts, composer: shownComposer, open, cancel, submit };
}
