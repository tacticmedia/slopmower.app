import '@github/markdown-toolbar-element';
import { BoldIcon, CodeIcon, HeadingIcon, type Icon, ItalicIcon, LinkIcon, ListOrderedIcon, ListUnorderedIcon, QuoteIcon, TasklistIcon } from '@primer/octicons-react';
import { ActionBar, Textarea } from '@primer/react';
import { Tabs, useTab, useTabList, useTabPanel } from '@primer/react/experimental';
import { type DetailedHTMLProps, type HTMLAttributes, type KeyboardEvent, lazy, type ReactNode, type RefObject, Suspense, useRef } from 'react';
import { flushSync } from 'react-dom';
import { isPressed, type ModShortcut } from '../layout/modShortcut';
import { indentLines } from './indentLines';
import styles from './MarkdownEditor.module.css';

const MarkdownBody = lazy(() => import('./MarkdownBody').then(({ MarkdownBody }) => ({ default: MarkdownBody })));

type FormatTag = 'md-header' | 'md-bold' | 'md-italic' | 'md-quote' | 'md-code' | 'md-link' | 'md-unordered-list' | 'md-ordered-list' | 'md-task-list';

type ElementProps = DetailedHTMLProps<HTMLAttributes<HTMLElement>, HTMLElement>;

declare module 'react' {
    namespace JSX {
        interface IntrinsicElements extends Record<FormatTag, ElementProps> {
            'markdown-toolbar': ElementProps & { for: string };
        }
    }
}

type Format = { label: string; icon: Icon; tag: FormatTag; shortcut?: ModShortcut };

const FORMATS: readonly Format[] = [
    { label: 'Heading', icon: HeadingIcon, tag: 'md-header' },
    { label: 'Bold', icon: BoldIcon, tag: 'md-bold', shortcut: { key: 'B', shift: false } },
    { label: 'Italic', icon: ItalicIcon, tag: 'md-italic', shortcut: { key: 'I', shift: false } },
    { label: 'Quote', icon: QuoteIcon, tag: 'md-quote', shortcut: { key: 'Period', shift: true } },
    { label: 'Code', icon: CodeIcon, tag: 'md-code', shortcut: { key: 'E', shift: false } },
    { label: 'Link', icon: LinkIcon, tag: 'md-link', shortcut: { key: 'K', shift: false } },
    { label: 'Bulleted list', icon: ListUnorderedIcon, tag: 'md-unordered-list', shortcut: { key: 'Digit8', shift: true } },
    { label: 'Numbered list', icon: ListOrderedIcon, tag: 'md-ordered-list', shortcut: { key: 'Digit7', shift: true } },
    { label: 'Task list', icon: TasklistIcon, tag: 'md-task-list' },
];

const PREVIEW_SHORTCUT: ModShortcut = { key: 'P', shift: true };

type Props = {
    /** The `id` of the text field. */
    id: string;
    value: string;
    onChange(value: string): void;
    preview: boolean;
    onPreviewChange(preview: boolean): void;
    'aria-label'?: string;
    placeholder?: string;
    readOnly: boolean;
    ref: RefObject<HTMLTextAreaElement | null>;
};

/**
 * The buttons and the shortcuts click hidden elements of markdown-toolbar-element, and Tab edits through
 * `execCommand`, so undo works. The text field stays mounted while Preview shows.
 */
export function MarkdownEditor({ id, value, onChange, preview, onPreviewChange, 'aria-label': ariaLabel, placeholder = 'Leave a comment', readOnly, ref }: Props) {
    const editor = useRef<HTMLDivElement>(null);
    const toolbar = useRef<HTMLElement>(null);
    const format = (tag: FormatTag): void => toolbar.current?.querySelector(tag)?.click();

    const pressKey = (event: KeyboardEvent<HTMLDivElement>): void => {
        if (isPressed(PREVIEW_SHORTCUT, event.nativeEvent)) {
            event.preventDefault();
            flushSync(() => onPreviewChange(!preview));
            (preview ? ref.current : editor.current?.querySelector<HTMLElement>('[role="tab"][aria-selected="true"]'))?.focus();
        }
    };

    const pressFieldKey = (event: KeyboardEvent<HTMLTextAreaElement>): void => {
        const field = event.currentTarget;
        // After one failed execCommand, as on a read-only field, markdown-toolbar-element sets the value without undo for good.
        if (readOnly || event.nativeEvent.isComposing) {
            return;
        }
        if ('Tab' === event.key && !event.ctrlKey && !event.metaKey && !event.altKey && field.selectionStart !== field.selectionEnd) {
            event.preventDefault();
            const { start, end, text } = indentLines(field.value, field.selectionStart, field.selectionEnd, event.shiftKey);
            if (text !== field.value.slice(start, end)) {
                field.setSelectionRange(start, end);
                document.execCommand('insertText', false, text);
                field.setSelectionRange(start, start + text.length);
            }

            return;
        }
        const pressed = FORMATS.find(({ shortcut }) => undefined !== shortcut && isPressed(shortcut, event.nativeEvent));
        if (undefined !== pressed) {
            event.preventDefault();
            format(pressed.tag);
        }
    };

    return (
        <div ref={editor} onKeyDown={pressKey} className={styles.editor}>
            <Tabs value={preview ? 'preview' : 'write'} onValueChange={({ value: tab }) => onPreviewChange('preview' === tab)}>
                <div className={styles.header}>
                    <EditorTabList>
                        <EditorTab value="write" label="Write" />
                        <EditorTab value="preview" label="Preview" />
                    </EditorTabList>
                    {!preview && (
                        <ActionBar aria-label="Formatting tools" size="small" flush className={styles.toolbar}>
                            {FORMATS.map(({ label, icon, tag }) => (
                                <ActionBar.IconButton key={tag} icon={icon} aria-label={label} disabled={readOnly} onClick={() => format(tag)} />
                            ))}
                        </ActionBar>
                    )}
                </div>
                <EditorPanel value="write">
                    <markdown-toolbar ref={toolbar} for={id} hidden>
                        {FORMATS.map(({ tag: Tag }) => (
                            <Tag key={Tag} />
                        ))}
                    </markdown-toolbar>
                    <Textarea
                        ref={ref}
                        id={id}
                        value={value}
                        onChange={(event) => onChange(event.target.value)}
                        onKeyDown={pressFieldKey}
                        aria-label={ariaLabel}
                        placeholder={placeholder}
                        block
                        resize="vertical"
                        rows={4}
                        readOnly={readOnly}
                    />
                </EditorPanel>
                <EditorPanel value="preview" className={styles.preview}>
                    {preview &&
                        ('' === value.trim() ? (
                            <p className={styles.empty}>Nothing to preview</p>
                        ) : (
                            <Suspense fallback={null}>
                                <MarkdownBody markdown={value} />
                            </Suspense>
                        ))}
                </EditorPanel>
            </Tabs>
        </div>
    );
}

function EditorTabList({ children }: { children: ReactNode }) {
    const { tabListProps } = useTabList<HTMLDivElement>({ 'aria-label': 'Editor' });

    return (
        <div {...tabListProps} className={styles.tabs}>
            {children}
        </div>
    );
}

function EditorTab({ value, label }: { value: string; label: string }) {
    const { tabProps } = useTab<HTMLButtonElement>({ value });

    return (
        <button type="button" {...tabProps} className={styles.tab}>
            <span data-content={label}>{label}</span>
        </button>
    );
}

function EditorPanel({ value, className, children }: { value: string; className?: string; children: ReactNode }) {
    const { tabPanelProps } = useTabPanel<HTMLDivElement>({ value });

    return (
        <div {...tabPanelProps} className={className}>
            {children}
        </div>
    );
}
