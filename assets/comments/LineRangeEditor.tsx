import { Link } from '@primer/react';
import { type KeyboardEvent, type Ref, useEffect, useImperativeHandle, useRef, useState } from 'react';
import type { LineRange } from '../code/lineHash';
import styles from './LineRangeEditor.module.css';
import { parseLineRange } from './lineRangeInput';
import { linesText } from './threadView';

export type LineRangeEditorHandle = {
    /** Applies open inputs. Null when they are invalid, after `onError` got the message. */
    apply(): LineRange | null;
};

type Props = {
    range: LineRange;
    lineCount: number;
    onChange(range: LineRange): void;
    onError(message: string | null): void;
    onOpenChange?: (open: boolean) => void;
    disabled: boolean;
    ref?: Ref<LineRangeEditorHandle>;
};

type Fields = { start: string; end: string };

/** "Update selection" turns the numbers into inputs, and "Done" or Enter applies them. */
export function LineRangeEditor({ range, lineCount, onChange, onError, onOpenChange, disabled, ref }: Props) {
    const [fields, setFields] = useState<Fields | null>(null);
    const startInput = useRef<HTMLInputElement>(null);
    const editing = null !== fields;

    useEffect(() => {
        if (!editing) {
            return;
        }
        startInput.current?.select();
        onOpenChange?.(true);

        return () => onOpenChange?.(false);
    }, [editing, onOpenChange]);

    const apply = (): LineRange | null => {
        if (null === fields) {
            return range;
        }
        const result = parseLineRange(fields.start, fields.end, lineCount);
        if (null === result.range) {
            onError(result.errors.start ?? result.errors.end ?? null);

            return null;
        }
        onError(null);
        setFields(null);
        onChange(result.range);

        return result.range;
    };
    useImperativeHandle(ref, () => ({ apply }));

    const pressKey = (event: KeyboardEvent<HTMLInputElement>): void => {
        if ('Enter' === event.key) {
            event.preventDefault();
            apply();
        } else if ('Escape' === event.key) {
            event.preventDefault();
            onError(null);
            setFields(null);
        }
    };
    const number = (open: Fields, name: keyof Fields, label: string) => (
        <input
            ref={'start' === name ? startInput : undefined}
            className={styles.number}
            // The digits of the last line, so typing never widens the field.
            style={{ width: `calc(${String(lineCount).length}ch + var(--base-size-8))` }}
            aria-label={label}
            inputMode="numeric"
            autoComplete="off"
            value={open[name]}
            onChange={(event) => setFields({ ...open, [name]: event.target.value })}
            onKeyDown={pressKey}
            readOnly={disabled}
        />
    );

    return (
        <span className={styles.editor}>
            {null === fields ? (
                <span data-testid="draft-lines">{linesText(range)}</span>
            ) : (
                <span data-testid="draft-line-fields">
                    Lines {number(fields, 'start', 'Start line')} to {number(fields, 'end', 'End line')}
                </span>
            )}
            <Link as="button" type="button" className={styles.toggle} onClick={editing ? () => void apply() : () => setFields({ start: String(range.start), end: String(range.end) })} disabled={disabled}>
                {editing ? 'Done' : 'Update selection'}
            </Link>
        </span>
    );
}
