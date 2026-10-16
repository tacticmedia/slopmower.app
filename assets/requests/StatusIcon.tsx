import { CheckCircleIcon, type Icon, IssueClosedIcon, IssueOpenedIcon, IssueReopenedIcon, IssueTrackedByIcon, SkipIcon } from '@primer/octicons-react';
import type { RequestStatus } from '../api/types/common';
import styles from './StatusIcon.module.css';
import { STATUS_TEXT } from './statusText';

const ICONS: Record<RequestStatus, Icon> = {
    open: IssueOpenedIcon,
    in_progress: IssueTrackedByIcon,
    ready_for_review: IssueClosedIcon,
    verified: CheckCircleIcon,
    reopened: IssueReopenedIcon,
    wont_do: SkipIcon,
};

/** The colours follow the `StatusLabel` variants. */
export function StatusIcon({ status }: { status: RequestStatus }) {
    const StatusOcticon = ICONS[status];

    return (
        <span role="img" aria-label={STATUS_TEXT[status]} title={STATUS_TEXT[status]} className={styles.icon} data-testid="status-label" data-status={status}>
            <StatusOcticon aria-hidden />
        </span>
    );
}
