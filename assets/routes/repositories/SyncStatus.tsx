import { Label, type LabelColorOptions, RelativeTime } from '@primer/react';
import type { Sync, SyncState } from '../../api/types/repository';
import styles from './SyncStatus.module.css';

const SYNC_LABELS: Record<SyncState, { text: string; variant: LabelColorOptions }> = {
    cloning: { text: 'Cloning', variant: 'attention' },
    fetching: { text: 'Fetching', variant: 'accent' },
    idle: { text: 'Ready', variant: 'success' },
    failed: { text: 'Failed', variant: 'danger' },
    unavailable: { text: 'Unavailable', variant: 'secondary' },
};

export function SyncStatus({ sync }: { sync: Sync }) {
    const { text, variant } = SYNC_LABELS[sync.state];

    return (
        <div className={styles.status}>
            <span>
                <Label variant={variant} data-testid="sync-state">
                    {text}
                </Label>
                {null !== sync.lastFetchedAt && (
                    <span className={styles.fetched}>
                        {' '}
                        fetched <RelativeTime datetime={sync.lastFetchedAt} />
                    </span>
                )}
            </span>
            {null !== sync.lastError && (
                <span className={styles.error} data-testid="sync-error">
                    {sync.lastError}
                </span>
            )}
        </div>
    );
}
