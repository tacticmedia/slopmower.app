import { Button, Heading, TextInput } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { type FormEvent, type KeyboardEvent, type MouseEvent, useEffect, useRef, useState } from 'react';
import type { Request as ReviewRequest } from '../api/types/request';
import { TITLE_MAX_CHARACTERS } from '../comments/CommentBox';
import styles from './RequestTitle.module.css';
import { useRequestCommand } from './useRequestCommand';

type Props = Pick<ReviewRequest, 'title' | 'summary' | 'allowedActions'>;

/**
 * The h1 of the request detail. The editor closes when Q23 succeeds and keeps the text when it fails. An unchanged
 * title closes it without a write.
 */
export function RequestTitle({ title, summary, allowedActions }: Props) {
    const { busy, error, dialog, send, openDialog, closeDialog } = useRequestCommand<'title'>();
    const [text, setText] = useState('');
    const editing = 'title' === dialog;
    const editButton = useRef<HTMLButtonElement>(null);
    const wasEditing = useRef(false);

    useEffect(() => {
        if (wasEditing.current && !editing) {
            editButton.current?.focus();
        }
        wasEditing.current = editing;
    }, [editing]);

    const edit = (event: MouseEvent<HTMLButtonElement>): void => {
        setText(title ?? '');
        openDialog('title', event);
    };
    const save = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        const next = text.trim();
        if ('' === next || busy) {
            return;
        }
        if (next === title) {
            closeDialog();
        } else {
            send({ intent: 'set_title', input: { title: next } });
        }
    };
    const pressKey = (event: KeyboardEvent<HTMLFormElement>): void => {
        if ('Escape' === event.key && !busy) {
            event.preventDefault();
            closeDialog();
        }
    };

    if (editing) {
        return (
            <form className={styles.editor} onSubmit={save} onKeyDown={pressKey} data-testid="title-editor">
                <TextInput
                    aria-label="Title"
                    value={text}
                    onChange={(event) => setText(event.target.value)}
                    maxLength={TITLE_MAX_CHARACTERS}
                    size="large"
                    className={styles.input}
                    readOnly={busy}
                    autoFocus
                />
                <div className={styles.buttons}>
                    <Button type="submit" variant="primary" disabled={'' === text.trim()} loading={busy}>
                        Save
                    </Button>
                    <Button onClick={closeDialog} disabled={busy}>
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

    return (
        <div className={styles.heading}>
            <Heading as="h1" className={null === title ? `${styles.text} ${styles.untitled}` : styles.text}>
                {summary}
            </Heading>
            {allowedActions.includes('set_title') && (
                <Button ref={editButton} size="small" className={styles.edit} onClick={edit}>
                    {null === title ? 'Add a title' : 'Edit'}
                </Button>
            )}
        </div>
    );
}
