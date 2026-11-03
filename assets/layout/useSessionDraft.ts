import { useCallback, useState } from 'react';

/** The only project value of this file. */
const KEY_PREFIX = 'slopmower.draft:';

type Update = string | ((current: string) => string);

/**
 * The text of a form field, kept in `sessionStorage` under `key`, so a reload of the tab shows it again. Stored text
 * wins over `initial`. Only `setText` writes, and text equal to `initial` removes the key, so a render never stores a
 * key again after {@link removeSessionDraft}. A null `key` keeps the text in memory; its text stays when a key comes.
 * Without `sessionStorage`, the text stays in memory. To discard the text, call {@link removeSessionDraft}, then
 * `revert()`.
 *
 * @return [text, setText, revert], where `revert()` shows the stored text again, or `initial` without one, and does
 *         not write
 */
export function useSessionDraft(key: string | null, initial = ''): [string, (update: Update) => void, () => void] {
    const [state, setState] = useState(() => ({ key, text: storedText(key) ?? initial }));
    let current = state;
    if (state.key !== key) {
        current = { key, text: storedText(key) ?? (null === state.key ? state.text : initial) };
        setState(current);
    }

    const setText = useCallback(
        (update: Update): void =>
            setState((previous) => {
                const text = 'function' === typeof update ? update(previous.text) : update;
                if (null !== key) {
                    if (text === initial) {
                        removeSessionDraft(key);
                    } else {
                        writeSessionDraft(key, text);
                    }
                }

                return { key, text };
            }),
        [key, initial],
    );

    const revert = useCallback((): void => setState({ key, text: storedText(key) ?? initial }), [key, initial]);

    return [current.text, setText, revert];
}

export function readSessionDraft(key: string): string | null {
    try {
        return sessionStorage.getItem(KEY_PREFIX + key);
    } catch {
        return null;
    }
}

export function removeSessionDraft(key: string): void {
    try {
        sessionStorage.removeItem(KEY_PREFIX + key);
    } catch {}
}

/** The stored keys that start with `prefix`. */
export function sessionDraftKeys(prefix: string): string[] {
    try {
        return Object.keys(sessionStorage)
            .filter((stored) => stored.startsWith(KEY_PREFIX + prefix))
            .map((stored) => stored.slice(KEY_PREFIX.length));
    } catch {
        return [];
    }
}

export function removeSessionDrafts(): void {
    for (const key of sessionDraftKeys('')) {
        removeSessionDraft(key);
    }
}

function storedText(key: string | null): string | null {
    return null === key ? null : readSessionDraft(key);
}

/** A full storage keeps the text in memory only. */
function writeSessionDraft(key: string, text: string): void {
    try {
        sessionStorage.setItem(KEY_PREFIX + key, text);
    } catch {}
}
