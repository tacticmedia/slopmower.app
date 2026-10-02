import { Dialog, FormControl, TextInput } from '@primer/react';
import { type FormEvent, type ReactNode, useEffect, useId, useRef, useState } from 'react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../api/actionResult';
import { ActionErrorMessage } from '../layout/ActionErrorMessage';
import type { SettingsIntent } from '../routes/settings/settingsData';
import styles from './Settings.module.css';

type Props = {
    title: string;
    /** The text that the user types: the repository name or the login. */
    expected: string;
    confirmButtonContent: string;
    intent: (confirm: string) => SettingsIntent;
    onClose: () => void;
    children: ReactNode;
};

/**
 * The confirm button works only when the typed text equals `expected`. While the write runs, the dialog does not close.
 * The settings action answers null after the write, and the dialog closes.
 */
export function TypedConfirmationDialog({ title, expected, confirmButtonContent, intent, onClose, children }: Props) {
    const fetcher = useFetcher<ActionResult>();
    const [typed, setTyped] = useState('');
    const formId = useId();
    const input = useRef<HTMLInputElement>(null);
    const busy = 'idle' !== fetcher.state;
    const closeUnlessBusy = (): void => {
        if (!busy) {
            onClose();
        }
    };

    useEffect(() => {
        if ('idle' === fetcher.state && null === fetcher.data) {
            onClose();
        }
    }, [fetcher.state, fetcher.data, onClose]);

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        if (!busy && expected === typed) {
            void fetcher.submit(intent(typed), { method: 'post', action: '/settings', encType: 'application/json' });
        }
    };

    return (
        <Dialog
            title={title}
            onClose={closeUnlessBusy}
            initialFocusRef={input}
            footerButtons={[
                { content: 'Cancel', onClick: closeUnlessBusy, disabled: busy },
                { content: confirmButtonContent, buttonType: 'danger', type: 'submit', form: formId, disabled: expected !== typed, loading: busy },
            ]}
        >
            <form id={formId} onSubmit={submit} className={styles.form}>
                {children}
                <FormControl required>
                    <FormControl.Label>
                        To confirm, type <code>{expected}</code>
                    </FormControl.Label>
                    <TextInput ref={input} value={typed} onChange={(event) => setTyped(event.target.value)} block autoComplete="off" spellCheck={false} />
                </FormControl>
                <ActionErrorMessage result={fetcher.data} />
            </form>
        </Dialog>
    );
}
