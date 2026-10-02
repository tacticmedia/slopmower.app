import { CheckIcon, CopyIcon } from '@primer/octicons-react';
import { Button, VisuallyHidden } from '@primer/react';
import { useEffect, useState } from 'react';
import styles from './CopyButton.module.css';

type CopyState = 'idle' | 'copied' | 'failed';

const RESULT_DURATION_MS = 2000;

export function CopyButton({ text, label }: { text: string; label: string }) {
    const [state, setState] = useState<CopyState>('idle');

    useEffect(() => {
        if ('idle' === state) {
            return;
        }
        const timer = setTimeout(() => setState('idle'), RESULT_DURATION_MS);

        return () => clearTimeout(timer);
    }, [state]);

    // navigator.clipboard is undefined outside a secure context. The promise chain turns that TypeError into a failure.
    const copy = (): void => {
        Promise.resolve()
            .then(() => navigator.clipboard.writeText(text))
            .then(
                () => setState('copied'),
                () => setState('failed'),
            );
    };

    return (
        <span className={styles.copy}>
            <Button size="small" leadingVisual={'copied' === state ? CheckIcon : CopyIcon} onClick={copy}>
                {{ idle: label, copied: 'Copied', failed: 'Copy failed' }[state]}
            </Button>
            {'failed' === state && <span className={styles.hint}>Select the text and copy it.</span>}
            <VisuallyHidden role="status">{'copied' === state ? 'Copied.' : ''}</VisuallyHidden>
        </span>
    );
}
