import type { ReactNode } from 'react';

type Props = { lines: readonly string[]; footer?: ReactNode };

/**
 * The invoice lines.
 */
export function InvoiceView({ lines, footer }: Props) {
    return (
        <ul>
            {lines.map((line) => <li key={line}>{line}</li>)}
            {footer}
        </ul>
    );
}

export const Empty = <T,>(props: { items: T[] }) => <p>{props.items.length}</p>;
