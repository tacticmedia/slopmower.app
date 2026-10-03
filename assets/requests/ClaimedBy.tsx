import type { Actor } from '../api/types/actor';
import type { DateTime } from '../api/types/common';
import { dateTimeFormat } from '../settings/TokenTable';
import { claimAge } from './claimAge';
import styles from './Requests.module.css';

/**
 * The claiming agent and the claim age, decision D6. The age is computed at render time.
 */
export function ClaimedBy({ claimedBy, claimedAt }: { claimedBy: Actor; claimedAt: DateTime }) {
    return (
        <span data-testid="claimed-by">
            {claimedBy.name}{' '}
            <time dateTime={claimedAt} title={dateTimeFormat.format(new Date(claimedAt))} className={styles.muted}>
                for {claimAge(claimedAt, new Date())}
            </time>
        </span>
    );
}
