import { Button, Checkbox, FormControl, Textarea, TextInput } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { type FormEvent, type KeyboardEvent, type Ref, useEffect, useImperativeHandle, useRef, useState } from 'react';
import { isPressed, type ModShortcut } from '../layout/modShortcut';
import styles from './CommentBox.module.css';

/** About 2 s at 60 frames per second. */
const FOCUS_ATTEMPTS = 120;

const SUBMIT_SHORTCUT: ModShortcut = { key: 'Enter', shift: false };

export const TITLE_MAX_CHARACTERS = 200;

/** `title` is absent without a title field or when the field is blank, so Q2, D2, and D3 get no `title` key. */
export type CommentText = { title?: string; body: string; requireApproval: boolean };

export type CommentBoxHandle = {
    /** Appends the text as a Markdown quote and focuses the field. */
    quote(text: string): void;
};

type Props = {
    /** The accessible name of the text field, or of the form when the box has a title field. */
    label: string;
    /** Shows `label` as the header of the box. */
    labelVisible?: boolean;
    submitLabel: string;
    /**
     * False when the submit was not accepted, for example while another write runs. `requireApproval` is the "Ask for a
     * plan first" checkbox, false without it.
     */
    onSubmit(text: CommentText): boolean;
    /** A second write of the same text, the primary button. */
    alternative?: { label: string; onSubmit(text: CommentText): boolean };
    /** Shows the "Ask for a plan first" checkbox of a new request, with this initial state. */
    askForPlan?: boolean;
    /** The box opens a request: a "Title" field and a "Description" text field. */
    titled?: boolean;
    onCancel?: () => void;
    initialTitle?: string;
    initialBody?: string;
    error: string | null;
    busy: boolean;
    focusOnMount?: boolean;
    ref?: Ref<CommentBoxHandle>;
};

/**
 * The text stays while the write runs and clears when it succeeds, so a failed write keeps it. Mod+Enter runs
 * `onSubmit`, and Escape runs `onCancel`. Enter in the title field moves to the description.
 */
export function CommentBox({
    label,
    labelVisible = false,
    submitLabel,
    onSubmit,
    alternative,
    askForPlan,
    titled = false,
    onCancel,
    initialTitle = '',
    initialBody = '',
    error,
    busy,
    focusOnMount = false,
    ref,
}: Props) {
    const [title, setTitle] = useState(initialTitle);
    const [body, setBody] = useState(initialBody);
    const [requireApproval, setRequireApproval] = useState(askForPlan ?? false);
    const [sent, setSent] = useState(false);
    const textarea = useRef<HTMLTextAreaElement>(null);

    if (sent && !busy) {
        setSent(false);
        if (null === error) {
            setTitle('');
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

    const send = (write: (text: CommentText) => boolean): void => {
        const givenTitle = title.trim();
        if ('' !== body.trim() && !busy && write({ ...('' === givenTitle ? {} : { title: givenTitle }), body, requireApproval })) {
            setSent(true);
        }
    };
    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        send(onSubmit);
    };
    const pressKey = (event: KeyboardEvent<HTMLFormElement>): void => {
        if (event.nativeEvent.isComposing) {
            return;
        }
        if (isPressed(SUBMIT_SHORTCUT, event.nativeEvent)) {
            event.preventDefault();
            send(onSubmit);
        } else if ('Escape' === event.key && undefined !== onCancel && !busy) {
            event.preventDefault();
            onCancel();
        }
    };

    const pressTitleKey = (event: KeyboardEvent<HTMLInputElement>): void => {
        if ('Enter' === event.key && !event.nativeEvent.isComposing && !isPressed(SUBMIT_SHORTCUT, event.nativeEvent)) {
            event.preventDefault();
            textarea.current?.focus();
        }
    };
    const description = (
        <Textarea
            ref={textarea}
            value={body}
            onChange={(event) => setBody(event.target.value)}
            aria-label={titled ? undefined : label}
            placeholder="Leave a comment"
            block
            resize="vertical"
            rows={4}
            readOnly={busy}
        />
    );

    return (
        <form className={styles.box} onSubmit={submit} onKeyDown={pressKey} aria-label={titled ? label : undefined} data-testid="comment-box">
            {labelVisible && (
                <div className={styles.header} aria-hidden="true" data-testid="comment-box-header">
                    {label}
                </div>
            )}
            {titled ? (
                <>
                    <FormControl>
                        <FormControl.Label>Title</FormControl.Label>
                        <TextInput value={title} onChange={(event) => setTitle(event.target.value)} onKeyDown={pressTitleKey} maxLength={TITLE_MAX_CHARACTERS} block readOnly={busy} />
                        <FormControl.Caption>Optional.</FormControl.Caption>
                    </FormControl>
                    <FormControl>
                        <FormControl.Label>Description</FormControl.Label>
                        {description}
                    </FormControl>
                </>
            ) : (
                description
            )}
            {null !== error && (
                <InlineMessage variant="critical" role="alert" data-testid="comment-error">
                    {error}
                </InlineMessage>
            )}
            <div className={styles.actions}>
                {undefined !== askForPlan && (
                    <FormControl className={styles.askForPlan} disabled={busy}>
                        <Checkbox checked={requireApproval} onChange={(event) => setRequireApproval(event.target.checked)} />
                        <FormControl.Label>Ask for a plan first</FormControl.Label>
                    </FormControl>
                )}
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
