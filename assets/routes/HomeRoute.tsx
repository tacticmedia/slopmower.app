import { Heading, Link } from '@primer/react';
import { Link as RouterLink } from 'react-router';

export function HomeRoute() {
    return (
        <>
            <Heading as="h1">Slopmower</Heading>
            <p>
                Go to{' '}
                <Link as={RouterLink} to="/repositories">
                    Repositories
                </Link>{' '}
                to connect a repository and start a review.
            </p>
        </>
    );
}
