import { ConfirmationDialog } from '@primer/react';
import { createContext, type ReactNode, useCallback, useContext, useEffect, useId, useMemo, useState } from 'react';
import { type BlockerFunction, useBlocker } from 'react-router';
import { removeSessionDraft } from '../layout/useSessionDraft';

type Registry = (id: string, unsaved: boolean, draftKey: string | null) => void;

type Guard = {
    mark: Registry;
    /** Resolves true when no comment box holds unsaved text, or when the user discards it. */
    confirmDiscard(): Promise<boolean>;
};

const UnsavedComments = createContext<Guard>({ mark: () => undefined, confirmDiscard: () => Promise.resolve(true) });

/**
 * Asks before a navigation to another path, a reload, or a closed tab while a comment box holds unsaved text. React
 * Router allows one blocker per router, so this is the only one. "Discard" also removes the session drafts (D109).
 */
export function UnsavedCommentGuard({ children }: { children: ReactNode }) {
    const [unsaved, setUnsaved] = useState<ReadonlyMap<string, string | null>>(new Map());
    const mark = useMemo<Registry>(
        () => (id, isUnsaved, draftKey) =>
            setUnsaved((current) => {
                if (isUnsaved ? current.has(id) && current.get(id) === draftKey : !current.has(id)) {
                    return current;
                }
                const next = new Map(current);
                if (isUnsaved) {
                    next.set(id, draftKey);
                } else {
                    next.delete(id);
                }

                return next;
            }),
        [],
    );
    const guarding = unsaved.size > 0;
    const shouldBlock = useCallback<BlockerFunction>(({ currentLocation, nextLocation }) => guarding && currentLocation.pathname !== nextLocation.pathname, [guarding]);
    const blocker = useBlocker(shouldBlock);
    const [confirming, setConfirming] = useState<((discard: boolean) => void) | null>(null);
    const guard = useMemo<Guard>(
        () => ({ mark, confirmDiscard: () => (guarding ? new Promise<boolean>((resolve) => setConfirming(() => resolve)) : Promise.resolve(true)) }),
        [mark, guarding],
    );

    useEffect(() => {
        if (!guarding) {
            return;
        }
        const warn = (event: BeforeUnloadEvent): void => event.preventDefault();
        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [guarding]);

    const close = (discard: boolean): void => {
        if (discard) {
            for (const draftKey of unsaved.values()) {
                if (null !== draftKey) {
                    removeSessionDraft(draftKey);
                }
            }
        }
        if (null !== confirming) {
            setConfirming(null);
            confirming(discard);
        } else if ('blocked' === blocker.state) {
            if (discard) {
                blocker.proceed();
            } else {
                blocker.reset();
            }
        }
    };

    return (
        <UnsavedComments value={guard}>
            {children}
            {('blocked' === blocker.state || null !== confirming) && (
                <ConfirmationDialog
                    title="Discard your unsaved comment?"
                    confirmButtonContent="Discard"
                    confirmButtonType="danger"
                    cancelButtonContent="Keep editing"
                    onClose={(gesture) => close('confirm' === gesture)}
                >
                    A comment box on this page holds text that is not saved.
                </ConfirmationDialog>
            )}
        </UnsavedComments>
    );
}

export function useUnsavedComment(unsaved: boolean, draftKey: string | null = null): void {
    const { mark } = useContext(UnsavedComments);
    const id = useId();

    useEffect(() => {
        mark(id, unsaved, draftKey);

        return () => mark(id, false, null);
    }, [mark, id, unsaved, draftKey]);
}

/** For a write that leaves the page through a redirect of its action, which the blocker does not see. */
export function useConfirmDiscard(): () => Promise<boolean> {
    return useContext(UnsavedComments).confirmDiscard;
}
