import { Label, Link } from '@primer/react';
import { useId } from 'react';
import { Link as RouterLink } from 'react-router';
import type { CarryOverItem, CarryOverResult } from '../api/types/carry-over-result';
import { type OrphanReason, REQUEST_STATUSES, type RequestStatus } from '../api/types/common';
import { anchorLocation } from '../requests/anchorLocation';
import { STATUS_TEXT } from '../requests/statusText';
import styles from './CarryOverPanel.module.css';

const ORPHAN_TEXT: Record<OrphanReason, string> = {
    file_deleted: 'its file is gone',
    region_deleted: 'its code is gone',
    ambiguous: 'its code is in several places',
    content_not_found: 'its file is not text',
};

function isStatus(reason: RequestStatus | OrphanReason): reason is RequestStatus {
    return (REQUEST_STATUSES as readonly string[]).includes(reason);
}

function outcomeText(item: CarryOverItem): string {
    switch (item.outcome) {
        case 'carried':
            if ('changed' === item.location) {
                return 'Carried, the code changed';
            }

            return 'orphaned' === item.location ? 'Carried to the whole file, the code was not found' : 'Carried';
        case 'already_carried':
            return 'Carried earlier';
        case 'not_carried':
            return `Not carried: ${isStatus(item.reason) ? STATUS_TEXT[item.reason].toLowerCase() : ORPHAN_TEXT[item.reason]}`;
    }
}

type Props = {
    result: CarryOverResult;
    /** False on the page of the review that the requests were carried into. */
    linkToReview: boolean;
};

/**
 * Lists every request of the source review with its outcome, so no request is dropped silently.
 */
export function CarryOverPanel({ result, linkToReview }: Props) {
    const titleId = useId();

    return (
        <section className={styles.panel} aria-labelledby={titleId} data-testid="carry-over-result">
            <h3 id={titleId} className={styles.title}>
                Carried over requests
            </h3>
            <ul className={styles.items}>
                {result.items.map((item) => (
                    <li key={item.source.id} className={styles.item} data-testid="carry-over-item">
                        <Label variant={'not_carried' === item.outcome ? 'attention' : 'success'} data-testid="carry-over-outcome">
                            {outcomeText(item)}
                        </Label>
                        <Link as={RouterLink} to={`/requests/${item.requestId ?? item.source.id}`}>
                            {item.source.summary}
                        </Link>
                        <span className={styles.meta}>{null === item.source.anchor ? 'General' : anchorLocation(item.source.anchor)}</span>
                    </li>
                ))}
            </ul>
            {linkToReview && (
                <Link as={RouterLink} to={`/reviews/${result.reviewId}`}>
                    Open the review
                </Link>
            )}
        </section>
    );
}
