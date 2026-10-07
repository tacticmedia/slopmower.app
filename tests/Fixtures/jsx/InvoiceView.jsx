import { useState } from 'react';

/**
 * One invoice line.
 */
export function InvoiceLine({ line }) {
    const [open, setOpen] = useState(false);

    return <li onClick={() => setOpen(!open)}>{line.label}</li>;
}

export const InvoiceList = ({ lines }) => (
    <ul>
        {lines.map((line) => (
            <InvoiceLine key={line.id} line={line} />
        ))}
    </ul>
);

export default class InvoiceView extends React.Component {
    render() {
        return <InvoiceList lines={this.props.lines} />;
    }
}
