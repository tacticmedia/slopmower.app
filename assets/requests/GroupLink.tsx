import { Link } from '@primer/react';
import { Link as RouterLink } from 'react-router';

/** Opens the request list of the review, filtered by the group. */
export function GroupLink({ reviewId, group }: { reviewId: string; group: string }) {
    return (
        <Link as={RouterLink} to={`/reviews/${reviewId}/requests?${new URLSearchParams({ group }).toString()}`} data-testid="request-group">
            {group}
        </Link>
    );
}
