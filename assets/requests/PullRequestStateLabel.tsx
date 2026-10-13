import { StateLabel, type StateLabelProps } from '@primer/react';
import type { PullRequestState } from '../api/types/common';

type KnownState = Exclude<PullRequestState, 'unknown'>;

/** The Primer statuses of GitHub's pull request states. */
const STATUSES: Record<KnownState, StateLabelProps['status']> = {
    open: 'pullOpened',
    draft: 'draft',
    merged: 'pullMerged',
    closed: 'pullClosed',
};

const TEXT: Record<KnownState, string> = {
    open: 'Open',
    draft: 'Draft',
    merged: 'Merged',
    closed: 'Closed',
};

/**
 * The state of a linked pull request on GitHub. `unknown` shows nothing.
 */
export function PullRequestStateLabel({ state }: { state: PullRequestState }) {
    return 'unknown' === state ? null : (
        <StateLabel status={STATUSES[state]} size="small" data-testid="pull-request-state">
            {TEXT[state]}
        </StateLabel>
    );
}
