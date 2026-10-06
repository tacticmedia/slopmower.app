import { Link } from '@primer/react';
import { Link as RouterLink } from 'react-router';
import type { AnchorSummary } from '../api/types/anchor';
import { anchorHref, anchorLocation } from './anchorLocation';
import shared from './Requests.module.css';

export function AnchorLink({ reviewId, anchor }: { reviewId: string; anchor: AnchorSummary | null }) {
    if (null === anchor) {
        return (
            <span className={shared.muted} data-testid="general-request">
                General
            </span>
        );
    }

    return (
        <Link as={RouterLink} to={anchorHref(reviewId, anchor)} className={shared.location}>
            {anchorLocation(anchor)}
        </Link>
    );
}
