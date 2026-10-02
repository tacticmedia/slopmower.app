import { useCallback, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import { formatLineHash, type LineRange, parseLineHash } from '../../code/lineHash';

/**
 * The selection follows the line hash, and a finished selection writes it back.
 */
export function useLineSelection(path: string, lineCount: number | null) {
    const { hash } = useLocation();
    const navigate = useNavigate();
    const [link, setLink] = useState({ path, hash });
    const [selection, setSelection] = useState(() => linkedRange(hash, lineCount));

    if (link.path !== path || link.hash !== hash) {
        setLink({ path, hash });
        setSelection(linkedRange(hash, lineCount));
    }

    const commit = useCallback(
        // Clearing the hash is not a hash-only change for the router, which would run every loader again.
        (range: LineRange | null) => void navigate({ hash: null === range ? '' : formatLineHash(range) }, { replace: true, defaultShouldRevalidate: false }),
        [navigate],
    );

    const select = useCallback(
        (range: LineRange | null) => {
            setSelection(range);
            commit(range);
        },
        [commit],
    );

    const clear = useCallback(() => select(null), [select]);

    return { selection, change: setSelection, commit, select, clear };
}

function linkedRange(hash: string, lineCount: number | null): LineRange | null {
    const range = parseLineHash(hash);

    return null !== range && null !== lineCount && range.end <= lineCount ? range : null;
}
