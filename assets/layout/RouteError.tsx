import { Heading, Link } from '@primer/react';
import { captureException } from '@sentry/react';
import { useEffect } from 'react';
import { isRouteErrorResponse, Link as RouterLink, useRouteError } from 'react-router';
import { ApiError } from '../api/client';
import styles from './StatusPage.module.css';

type ErrorDescription = {
    title: string;
    detail: string;
    signedOut: boolean;
};

function describeError(error: unknown): ErrorDescription {
    if (error instanceof ApiError) {
        return 401 === error.status
            ? { title: 'Signed out', detail: 'Your session has ended. Sign in again.', signedOut: true }
            : { title: 'Something went wrong', detail: error.message, signedOut: false };
    }

    if (isRouteErrorResponse(error)) {
        return 404 === error.status
            ? { title: 'Page not found', detail: 'No page exists at this address.', signedOut: false }
            : { title: `${error.status} ${error.statusText}`, detail: 'The page could not be shown.', signedOut: false };
    }

    return { title: 'Something went wrong', detail: error instanceof Error ? error.message : 'An unexpected error occurred.', signedOut: false };
}

/**
 * `embedded` renders inside the root layout, so the error of a screen keeps the header. React Router reports no error
 * that it renders here (D86).
 */
export function RouteError({ embedded = false }: { embedded?: boolean }) {
    const error = useRouteError();
    const { title, detail, signedOut } = describeError(error);

    useEffect(() => {
        if (!isRouteErrorResponse(error)) {
            captureException(error);
        }
    }, [error]);
    const Container = embedded ? 'section' : 'main';

    return (
        <Container className={embedded ? undefined : styles.page} data-testid="route-error">
            <Heading as="h1">{title}</Heading>
            <p>{detail}</p>
            {signedOut ? (
                <Link href="/login">Sign in</Link>
            ) : (
                <Link as={RouterLink} to="/">
                    Go to the start page
                </Link>
            )}
        </Container>
    );
}
