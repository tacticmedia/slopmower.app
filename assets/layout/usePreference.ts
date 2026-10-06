import { useCallback, useState } from 'react';

/** A per-browser UI preference in `localStorage`, off by default. */
export function usePreference(name: 'focus' | 'wrap'): [boolean, (on: boolean) => void] {
    const key = `holistic-review.${name}`;
    const [on, setOn] = useState(() => {
        try {
            return '1' === localStorage.getItem(key);
        } catch {
            return false;
        }
    });
    const set = useCallback(
        (next: boolean) => {
            setOn(next);
            try {
                if (next) {
                    localStorage.setItem(key, '1');
                } else {
                    localStorage.removeItem(key);
                }
            } catch {}
        },
        [key],
    );

    return [on, set];
}
