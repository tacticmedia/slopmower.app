import { Button, Textarea } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { type FormEvent, type Ref, useEffect, useImperativeHandle, useRef, useState } from 'react';
import styles from './CommentBox.module.css';

/** About 2 s at 60 frames per second. */
const FOCUS_ATTEMPTS = 120;

export type CommentBoxHandle = {
    /** Appends the text as a Markdown quote and focuses the field. */
    quote(text: string): void;
};

type Props = {
    /** The accessible name of the text field. */
    label: string;
    submitLabel: string;
    /** False when the submit was not accepted, for example while another write runs. */
    onSubmit(body: string): boolean;
    /** A second write of the same text, the primary button. */
    alternative?: { label: string; onSubmit(body: string): boolean };
    onCancel?: () => void;
    initialBody?: string;
    error: string | null;
    busy: boolean;
    focusOnMount?: boolean;
    ref?: Ref<CommentBoxHandle>;
};

/**
 * The text stays while the write runs and clears when it succeeds, so a failed write keeps it.
 */
export function CommentBox({ label, submitLabel, onSubmit, alternative, onCancel, initialBody = '', error, busy, focusOnMount = false, ref }: Props) {
    const [body, setBody] = useState(initialBody);
    const [sent, setSent] = useState(false);
    const textarea = useRef<HTMLTextAreaElement>(null);

    if (sent && !busy) {
        setSent(false);
        if (null === error) {
            setBody('');
        }
    }

    useImperativeHandle(
        ref,
        () => ({
            quote: (text: string) => {
                setBody((current) => `${current}${'' === current ? '' : '\n\n'}${quoted(text)}\n\n`);
                // After a menu that calls this has returned the focus to its button.
                requestAnimationFrame(() => textarea.current?.focus());
            },
        }),
        [],
    );

    useEffect(() => {
        if (focusOnMount) {
            return focusWhenRendered(textarea);
        }
    }, [focusOnMount]);

    const send = (write: (text: string) => boolean): void => {
        if ('' !== body.trim() && !busy && write(body)) {
            setSent(true);
        }
    };
    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        send(onSubmit);
    };

    return (
        <form className={styles.box} onSubmit={submit} data-testid="comment-box">
            <Textarea
                ref={textarea}
                value={body}
                onChange={(event) => setBody(event.target.value)}
                aria-label={label}
                placeholder="Leave a comment"
                block
                resize="vertical"
                rows={4}
                readOnly={busy}
            />
            {null !== error && (
                <InlineMessage variant="critical" role="alert" data-testid="comment-error">
                    {error}
                </InlineMessage>
            )}
            <div className={styles.actions}>
                {undefined !== onCancel && (
                    <Button onClick={onCancel} disabled={busy}>
                        Cancel
                    </Button>
                )}
                <Button type="submit" variant={undefined === alternative ? 'primary' : 'default'} disabled={'' === body.trim()} loading={busy}>
                    {submitLabel}
                </Button>
                {undefined !== alternative && (
                    <Button variant="primary" onClick={() => send(alternative.onSubmit)} disabled={'' === body.trim()} loading={busy}>
                        {alternative.label}
                    </Button>
                )}
            </div>
        </form>
    );
}

function quoted(text: string): string {
    return text
        .split('\n')
        .map((line) => `> ${line}`)
        .join('\n');
}

/**
 * Content in an annotation slot of the code view renders only after the view adds the slot for its line.
 */
function focusWhenRendered(target: { readonly current: HTMLTextAreaElement | null }): () => void {
    let frame = 0;
    let attempts = 0;
    const attempt = (): void => {
        const element = target.current;
        if (null !== element && element.getClientRects().length > 0) {
            element.focus();
        } else if (++attempts < FOCUS_ATTEMPTS) {
            frame = requestAnimationFrame(attempt);
        }
    };
    attempt();

    return () => cancelAnimationFrame(frame);
}
