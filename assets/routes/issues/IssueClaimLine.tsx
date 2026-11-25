import { Button } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import type { IssueClaim } from '../../api/types/issue';
import { dateTimeFormat } from '../../settings/TokenTable';
import styles from './Issues.module.css';
import { useIssueWrite } from './useIssueWrite';

/** The agent session that holds the issue (D130). "Release" sends I11. */
export function IssueClaimLine({ claim }: { claim: IssueClaim }) {
    const { busy, error, send } = useIssueWrite();

    return (
        <div className={styles.state} data-testid="issue-claim">
            <span>
                Held by {claim.holder.name}, session <code>{claim.session}</code>, until <time dateTime={claim.until}>{dateTimeFormat.format(new Date(claim.until))}</time>
            </span>
            <Button size="small" loading={busy} onClick={() => send({ intent: 'release', input: null })}>
                Release
            </Button>
            {null !== error && (
                <InlineMessage variant="critical" role="alert" className={styles.statusError}>
                    {error}
                </InlineMessage>
            )}
        </div>
    );
}
