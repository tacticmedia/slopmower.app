import { Button, Checkbox, FormControl, Select, TextInput } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { type FormEvent, type KeyboardEvent, type Ref, useEffect, useId, useImperativeHandle, useRef, useState } from 'react';
import { REQUEST_TYPES, type RequestType } from '../api/types/common';
import { isPressed, type ModShortcut } from '../layout/modShortcut';
import { REQUEST_TYPE_NAMES } from '../requests/RequestTypeLabel';
import styles from './CommentBox.module.css';
import { MarkdownEditor } from './MarkdownEditor';
import { useUnsavedComment } from './UnsavedCommentGuard';

/** About 2 s at 60 frames per second. */
const FOCUS_ATTEMPTS = 120;

const SUBMIT_SHORTCUT: ModShortcut = { key: 'Enter', shift: false };

export const TITLE_MAX_CHARACTERS = 200;

/** `title` is absent without a title field or when the field is blank, so Q2, D2, and D3 get no `title` key. */
export type CommentText = { title?: string; body: string; requireApproval: boolean; type: RequestType };

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
     * plan first" checkbox, false without it and for a question. `type` is the "Type" select, change without it.
     */
    onSubmit(text: CommentText): boolean;
    /** Shows the "Type" select and the "Ask for a plan first" checkbox of a new request, with this initial state. */
    askForPlan?: boolean;
    initialType?: RequestType;
    /** The box opens a request: a "Title" field and a "Description" text field. */
    titled?: boolean;
    onCancel?: () => void;
    initialTitle?: string;
    initialBody?: string;
    /** The initial text is saved, so only a change is unsaved. Otherwise any text is unsaved. */
    initialTextSaved?: boolean;
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
    askForPlan,
    initialType = 'change',
    titled = false,
    onCancel,
    initialTitle = '',
    initialBody = '',
    initialTextSaved = false,
    error,
    busy,
    focusOnMount = false,
    ref,
}: Props) {
    const [title, setTitle] = useState(initialTitle);
    const [body, setBody] = useState(initialBody);
    const [requireApproval, setRequireApproval] = useState(askForPlan ?? false);
    const [type, setType] = useState(initialType);
    const [sent, setSent] = useState(false);
    const [preview, setPreview] = useState(false);
    const textarea = useRef<HTMLTextAreaElement>(null);
    const editorId = useId();
    const typeId = useId();
    useUnsavedComment(!sent && (initialTextSaved ? title !== initialTitle || body !== initialBody : '' !== title.trim() || '' !== body.trim()));

    if (sent && !busy) {
        setSent(false);
        if (null === error) {
            setTitle('');
            setBody('');
            setPreview(false);
        }
    }

    useImperativeHandle(
        ref,
        () => ({
            quote: (text: string) => {
                setPreview(false);
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

    const send = (): void => {
        const givenTitle = title.trim();
        if ('' !== body.trim() && !busy && onSubmit({ ...('' === givenTitle ? {} : { title: givenTitle }), body, requireApproval: 'question' !== type && requireApproval, type })) {
            setSent(true);
        }
    };
    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        send();
    };
    const pressKey = (event: KeyboardEvent<HTMLFormElement>): void => {
        if (event.nativeEvent.isComposing) {
            return;
        }
        if (isPressed(SUBMIT_SHORTCUT, event.nativeEvent)) {
            event.preventDefault();
            send();
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
        <MarkdownEditor
            ref={textarea}
            id={editorId}
            value={body}
            onChange={setBody}
            preview={preview}
            onPreviewChange={setPreview}
            aria-label={titled ? undefined : label}
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
                    <FormControl id={editorId}>
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
                    <div className={styles.requestOptions}>
                        <span className={styles.type}>
                            <label htmlFor={typeId} className={styles.typeLabel}>
                                Type
                            </label>
                            <Select id={typeId} value={type} onChange={(event) => setType(event.target.value)} disabled={busy}>
                                {REQUEST_TYPES.map((known) => (
                                    <Select.Option key={known} value={known}>
                                        {REQUEST_TYPE_NAMES[known]}
                                    </Select.Option>
                                ))}
                            </Select>
                        </span>
                        {'question' !== type && (
                            <FormControl disabled={busy}>
                                <Checkbox checked={requireApproval} onChange={(event) => setRequireApproval(event.target.checked)} />
                                <FormControl.Label>Ask for a plan first</FormControl.Label>
                            </FormControl>
                        )}
                    </div>
                )}
                {undefined !== onCancel && (
                    <Button onClick={onCancel} disabled={busy}>
                        Cancel
                    </Button>
                )}
                <Button type="submit" variant="primary" disabled={'' === body.trim()} loading={busy}>
                    {submitLabel}
                </Button>
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
 * Content in an annotation slot of the code view renders only after the view adds the slot for its line. While the view
 * lays out, it can hide the slot again, and the browser then drops the focus, so the field takes it back when it shows
 * again and nothing else has it.
 */
function focusWhenRendered(target: { readonly current: HTMLTextAreaElement | null }): () => void {
    let frame = 0;
    let attempts = 0;
    let focused = false;
    const attempt = (): void => {
        const element = target.current;
        const unclaimed = !focused || null === document.activeElement || document.body === document.activeElement;
        if (null !== element && element !== document.activeElement && unclaimed && element.getClientRects().length > 0) {
            // A scroll on a later focus lays the view out again.
            element.focus({ preventScroll: focused });
            focused = true;
        }
        if (++attempts < FOCUS_ATTEMPTS) {
            frame = requestAnimationFrame(attempt);
        }
    };
    attempt();

    return () => cancelAnimationFrame(frame);
}
