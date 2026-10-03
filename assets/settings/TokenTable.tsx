import { Button, ConfirmationDialog, Label, type LabelColorOptions, VisuallyHidden } from '@primer/react';
import { type Column, DataTable } from '@primer/react/experimental';
import { useState } from 'react';
import { useFetcher } from 'react-router';
import type { ApiToken, ApiTokenState } from '../api/types/api-token';
import type { DateTime } from '../api/types/common';
import type { SettingsIntent } from '../routes/settings/settingsData';
import styles from './Settings.module.css';

const STATE_LABELS: Record<ApiTokenState, { text: string; variant: LabelColorOptions }> = {
    active: { text: 'Active', variant: 'success' },
    expired: { text: 'Expired', variant: 'attention' },
    revoked: { text: 'Revoked', variant: 'secondary' },
};

export const dateTimeFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

type Props = {
    tokens: ApiToken[];
    labelledBy: string;
    describedBy: string;
};

export function TokenTable({ tokens, labelledBy, describedBy }: Props) {
    const revoke = useFetcher();
    const [revoking, setRevoking] = useState<ApiToken | null>(null);

    const columns: Column<ApiToken>[] = [
        { header: 'Name', id: 'name', rowHeader: true, renderCell: (token) => token.name },
        { header: 'Scope', id: 'scope', renderCell: (token) => ('repository' === token.scope ? token.repositoryName : 'All repositories') },
        { header: 'Created', id: 'createdAt', renderCell: (token) => <DateTimeText value={token.createdAt} /> },
        { header: 'Last used', id: 'lastUsedAt', renderCell: (token) => <DateTimeText value={token.lastUsedAt} /> },
        { header: 'Expiry', id: 'expiresAt', renderCell: (token) => <DateTimeText value={token.expiresAt} /> },
        {
            header: 'State',
            id: 'state',
            renderCell: (token) => <Label variant={STATE_LABELS[token.state].variant}>{STATE_LABELS[token.state].text}</Label>,
        },
        {
            header: () => <VisuallyHidden>Actions</VisuallyHidden>,
            id: 'actions',
            align: 'end',
            renderCell: (token) =>
                'active' === token.state ? (
                    <Button size="small" variant="danger" onClick={() => setRevoking(token)}>
                        Revoke
                    </Button>
                ) : null,
        },
    ];

    const close = (gesture: 'confirm' | 'close-button' | 'cancel' | 'escape'): void => {
        if ('confirm' === gesture && null !== revoking) {
            const intent: SettingsIntent = { intent: 'revoke', tokenId: revoking.id };
            void revoke.submit(intent, { method: 'post', action: '/settings', encType: 'application/json' });
        }
        setRevoking(null);
    };

    return (
        <>
            {0 === tokens.length ? (
                <p className={styles.emptyTable}>No tokens yet. Create one to connect an agent.</p>
            ) : (
                <DataTable aria-labelledby={labelledBy} aria-describedby={describedBy} data={tokens} columns={columns} />
            )}
            {null !== revoking && (
                <ConfirmationDialog title={`Revoke ${revoking.name}?`} onClose={close} confirmButtonContent="Revoke" confirmButtonType="danger">
                    Agents that use this token lose access at once. The comments and changes that the token made keep its name.
                </ConfirmationDialog>
            )}
        </>
    );
}

function DateTimeText({ value }: { value: DateTime | null }) {
    return null === value ? 'Never' : <time dateTime={value}>{dateTimeFormat.format(new Date(value))}</time>;
}
