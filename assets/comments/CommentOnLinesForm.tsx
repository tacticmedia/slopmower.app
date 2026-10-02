import { AnchoredOverlay, Button, FormControl, TextInput } from '@primer/react';
import { type FormEvent, useRef, useState } from 'react';
import type { LineRange } from '../code/lineHash';
import styles from './CommentOnLinesForm.module.css';
import { type LineRangeErrors, parseLineRange } from './lineRangeInput';

type Props = {
    lineCount: number;
    onSubmit(range: LineRange): void;
};

/**
 * Decision D31: the keyboard way to comment on a range, because the gutter "+" works only with a pointer.
 */
export function CommentOnLinesForm({ lineCount, onSubmit }: Props) {
    const [open, setOpen] = useState(false);
    const [errors, setErrors] = useState<LineRangeErrors>({});
    const startInput = useRef<HTMLInputElement>(null);

    const close = (): void => {
        setOpen(false);
        setErrors({});
    };
    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const result = parseLineRange(String(form.get('start') ?? ''), String(form.get('end') ?? ''), lineCount);

        if (null === result.range) {
            setErrors(result.errors);

            return;
        }
        close();
        onSubmit(result.range);
    };

    return (
        <AnchoredOverlay
            open={open}
            onOpen={() => setOpen(true)}
            onClose={close}
            renderAnchor={(anchorProps) => (
                <Button {...anchorProps} size="small">
                    Comment on lines
                </Button>
            )}
            focusTrapSettings={{ initialFocusRef: startInput }}
            // The default focus zone is for menus: it takes the fields out of the Tab order.
            focusZoneSettings={{ disabled: true }}
            overlayProps={{ role: 'dialog', 'aria-label': 'Comment on lines' }}
        >
            <form className={styles.form} onSubmit={submit} noValidate data-testid="comment-on-lines-form">
                <div className={styles.fields}>
                    <FormControl required>
                        <FormControl.Label>Start line</FormControl.Label>
                        <TextInput ref={startInput} name="start" inputMode="numeric" autoComplete="off" size="small" className={styles.number} />
                        {undefined !== errors.start && <FormControl.Validation variant="error">{errors.start}</FormControl.Validation>}
                    </FormControl>
                    <FormControl required>
                        <FormControl.Label>End line</FormControl.Label>
                        <TextInput name="end" inputMode="numeric" autoComplete="off" size="small" className={styles.number} />
                        {undefined !== errors.end && <FormControl.Validation variant="error">{errors.end}</FormControl.Validation>}
                    </FormControl>
                </div>
                <div className={styles.actions}>
                    <Button size="small" onClick={close}>
                        Cancel
                    </Button>
                    <Button type="submit" size="small" variant="primary">
                        Comment
                    </Button>
                </div>
            </form>
        </AnchoredOverlay>
    );
}
