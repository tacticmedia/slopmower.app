import { Label, type LabelProps } from '@primer/react';
import type { LocationStatus, OrphanReason } from '../api/types/common';
import type { Location, LocationTarget } from '../api/types/location';
import { shortSha } from '../review/shortSha';
import { anchorLocation } from './anchorLocation';
import styles from './Requests.module.css';

const TARGET_TEXT: Record<LocationTarget, string> = {
    default_branch: 'At the default branch',
    requested: 'At the requested commit',
    linked_commit: 'In this commit',
};

const STATUS_VARIANTS: Record<LocationStatus, NonNullable<LabelProps['variant']>> = {
    exact: 'success',
    shifted: 'accent',
    changed: 'attention',
    orphaned: 'danger',
};

const STATUS_TEXT: Record<LocationStatus, string> = {
    exact: 'Exact',
    shifted: 'Shifted',
    changed: 'Changed',
    orphaned: 'Orphaned',
};

const REASON_TEXT: Record<OrphanReason, string> = {
    file_deleted: 'the file is deleted',
    region_deleted: 'the lines are deleted',
    ambiguous: 'the lines occur more than once',
    content_not_found: 'the file is not text',
};

/**
 * Where the anchor is at the target commit. Q3 gives null when the server clone is gone or has no default branch.
 */
export function LocationInfo({ location }: { location: Location | null }) {
    if (null === location) {
        return (
            <p className={styles.locationInfo} data-testid="location-info">
                <span className={styles.muted}>The location is not known.</span>
            </p>
        );
    }

    return (
        <p className={styles.locationInfo} data-testid="location-info" data-status={location.status}>
            <span>
                {TARGET_TEXT[location.target]} <code title={location.targetSha}>{shortSha(location.targetSha)}</code>
            </span>
            <Label variant={STATUS_VARIANTS[location.status]}>{STATUS_TEXT[location.status]}</Label>
            {'orphaned' === location.status ? (
                <span>Not found: {REASON_TEXT[location.reason]}.</span>
            ) : (
                <>
                    <span className={styles.location}>{anchorLocation(location)}</span>
                    <span className={styles.muted}>Confidence {Math.round(location.confidence * 100)}%</span>
                </>
            )}
        </p>
    );
}
