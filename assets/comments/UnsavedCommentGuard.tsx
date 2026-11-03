import { ConfirmationDialog } from '@primer/react';
import { createContext, type ReactNode, useCallback, useContext, useEffect, useId, useMemo, useState } from 'react';
import { type BlockerFunction, useBlocker } from 'react-router';

type Registry = (id: string, unsaved: boolean) => void;

const UnsavedComments = createContext<Registry>(() => undefined);

/**
 * Asks before a navigation to another path, a reload, or a closed tab while a comment box holds unsaved text. React
 * Router allows one blocker per router, so this is the only one.
 */
export function UnsavedCommentGuard({ children }: { children: ReactNode }) {
    const [unsaved, setUnsaved] = useState<ReadonlySet<string>>(new Set());
    const mark = useMemo<Registry>(
        () => (id, isUnsaved) =>
            setUnsaved((current) => {
                if (current.has(id) === isUnsaved) {
                    return current;
                }
                const next = new Set(current);
                if (isUnsaved) {
                    next.add(id);
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

    useEffect(() => {
        if (!guarding) {
            return;
        }
        const warn = (event: BeforeUnloadEvent): void => event.preventDefault();
        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [guarding]);

    return (
        <UnsavedComments value={mark}>
            {children}
            {'blocked' === blocker.state && (
                <ConfirmationDialog
                    title="Discard your unsaved comment?"
                    confirmButtonContent="Discard"
                    confirmButtonType="danger"
                    cancelButtonContent="Keep editing"
                    onClose={(gesture) => ('confirm' === gesture ? blocker.proceed() : blocker.reset())}
                >
                    A comment box on this page holds text that is not saved.
                </ConfirmationDialog>
            )}
        </UnsavedComments>
    );
}

export function useUnsavedComment(unsaved: boolean): void {
    const mark = useContext(UnsavedComments);
    const id = useId();

    useEffect(() => {
        mark(id, unsaved);

        return () => mark(id, false);
    }, [mark, id, unsaved]);
}
