import { CheckIcon, CopyIcon } from '@primer/octicons-react';
import { IconButton } from '@primer/react';
import { useEffect, useState } from 'react';

const FEEDBACK_MILLISECONDS = 2000;

type CopyState = 'idle' | 'copied' | 'failed';

type Props = {
    text: string;
    label: string;
};

export function CopyButton({ text, label }: Props) {
    const [state, setState] = useState<CopyState>('idle');

    useEffect(() => {
        if ('idle' === state) {
            return;
        }
        const timeout = setTimeout(() => setState('idle'), FEEDBACK_MILLISECONDS);

        return () => clearTimeout(timeout);
    }, [state]);

    // navigator.clipboard is undefined outside a secure context; the promise turns that TypeError into a failure.
    const copy = (): void => {
        Promise.resolve().then(() => navigator.clipboard.writeText(text)).then(
            () => setState('copied'),
            () => setState('failed'),
        );
    };

    return (
        <>
            <IconButton icon={'copied' === state ? CheckIcon : CopyIcon} aria-label={label} onClick={copy} />
            <span role="status">
                {'copied' === state && 'Copied.'}
                {'failed' === state && 'Copy failed. Select the text and copy it.'}
            </span>
        </>
    );
}
