import { Dialog, FormControl, Textarea } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { type FormEvent, type RefObject, useId, useRef, useState } from 'react';
import { type NoteRule, noteAccepted, REASON_MIN_CHARACTERS } from './noteRule';
import styles from './Requests.module.css';

const CAPTIONS: Record<NoteRule, string> = {
    optional: 'Optional.',
    required: 'Required.',
    reason: `At least ${REASON_MIN_CHARACTERS} characters.`,
};

export type NoteDialogSpec = {
    title: string;
    subtitle: string;
    fieldLabel: string;
    rule: NoteRule;
    submitLabel: string;
    buttonType: 'primary' | 'danger';
};

type Props = NoteDialogSpec & {
    busy: boolean;
    error: string | null;
    /** The trimmed text. Empty only for an optional note. */
    onSubmit(text: string): void;
    onClose(): void;
    returnFocusRef: RefObject<HTMLElement | null>;
};

/**
 * While the write runs, the dialog neither submits again nor closes.
 */
export function NoteDialog({ title, subtitle, fieldLabel, rule, submitLabel, buttonType, busy, error, onSubmit, onClose, returnFocusRef }: Props) {
    const [text, setText] = useState('');
    const field = useRef<HTMLTextAreaElement>(null);
    const formId = useId();
    const valid = noteAccepted(text, rule);
    const close = (): void => {
        if (!busy) {
            onClose();
        }
    };
    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        if (valid && !busy) {
            onSubmit(text.trim());
        }
    };

    return (
        <Dialog
            title={title}
            subtitle={subtitle}
            onClose={close}
            initialFocusRef={field}
            returnFocusRef={returnFocusRef}
            footerButtons={[
                { content: 'Cancel', onClick: close, disabled: busy },
                { content: submitLabel, buttonType, type: 'submit', form: formId, disabled: !valid, loading: busy },
            ]}
        >
            <form id={formId} onSubmit={submit} className={styles.dialogForm}>
                <FormControl required={'optional' !== rule}>
                    <FormControl.Label>{fieldLabel}</FormControl.Label>
                    <Textarea ref={field} value={text} onChange={(event) => setText(event.target.value)} block resize="vertical" rows={4} readOnly={busy} />
                    <FormControl.Caption>{CAPTIONS[rule]}</FormControl.Caption>
                </FormControl>
                {null !== error && (
                    <InlineMessage variant="critical" role="alert" data-testid="action-error">
                        {error}
                    </InlineMessage>
                )}
            </form>
        </Dialog>
    );
}
