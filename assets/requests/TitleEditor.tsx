import { Button, TextInput } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { type FormEvent, type KeyboardEvent, type RefObject, useEffect, useRef, useState } from 'react';
import { TITLE_MAX_CHARACTERS } from '../comments/CommentBox';
import styles from './RequestTitle.module.css';

type Props = {
    title: string;
    busy: boolean;
    error: string | null;
    /** The trimmed text, when it differs from `title`. */
    onSave(title: string): void;
    onClose(): void;
};

/** The editor of a one-line title, in place of its heading. An unchanged title closes it without a write. */
export function TitleEditor({ title, busy, error, onSave, onClose }: Props) {
    const [text, setText] = useState(title);
    const save = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        const next = text.trim();
        if ('' === next || busy) {
            return;
        }
        if (next === title) {
            onClose();
        } else {
            onSave(next);
        }
    };
    const pressKey = (event: KeyboardEvent<HTMLFormElement>): void => {
        if ('Escape' === event.key && !busy) {
            event.preventDefault();
            onClose();
        }
    };

    return (
        <form className={styles.editor} onSubmit={save} onKeyDown={pressKey} data-testid="title-editor">
            <TextInput aria-label="Title" value={text} onChange={(event) => setText(event.target.value)} maxLength={TITLE_MAX_CHARACTERS} size="large" className={styles.input} readOnly={busy} autoFocus />
            <div className={styles.buttons}>
                <Button type="submit" variant="primary" disabled={'' === text.trim()} loading={busy}>
                    Save
                </Button>
                <Button onClick={onClose} disabled={busy}>
                    Cancel
                </Button>
            </div>
            {null !== error && (
                <InlineMessage variant="critical" role="alert" className={styles.error} data-testid="title-error">
                    {error}
                </InlineMessage>
            )}
        </form>
    );
}

/** The ref of the button that opens the editor. The button gets the focus back when the editor closes. */
export function useEditButton(editing: boolean): RefObject<HTMLButtonElement | null> {
    const button = useRef<HTMLButtonElement>(null);
    const wasEditing = useRef(false);

    useEffect(() => {
        if (wasEditing.current && !editing) {
            button.current?.focus();
        }
        wasEditing.current = editing;
    }, [editing]);

    return button;
}
