import { Button, Heading, Link } from '@primer/react';
import { captureException } from '@sentry/react';
import { useEffect } from 'react';
import { isRouteErrorResponse, Link as RouterLink, useRevalidator, useRouteError } from 'react-router';
import { ApiError, isNetworkError } from '../api/client';
import { LoadingPage } from './LoadingPage';
import styles from './StatusPage.module.css';

const RELOADED_AT_KEY = 'slopmower.chunkReloadedAt';

const RELOAD_INTERVAL_MS = 60_000;

const chunkErrors = new WeakSet<Error>();

/** Decided when the chunk fails, so a later render does not change it. */
const reloadErrors = new WeakSet<Error>();

// D106. The error still reaches the error boundary, which reloads after the navigation sets the address.
window.addEventListener('vite:preloadError', ({ payload }) => {
    chunkErrors.add(payload);
    try {
        if (Date.now() - Number(sessionStorage.getItem(RELOADED_AT_KEY)) >= RELOAD_INTERVAL_MS) {
            reloadErrors.add(payload);
        }
    } catch {}
});

function reload(): void {
    try {
        sessionStorage.setItem(RELOADED_AT_KEY, String(Date.now()));
    } catch {}
    window.location.reload();
}

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

    if (error instanceof Error && chunkErrors.has(error)) {
        return { title: 'Loading failed', detail: 'Part of Slopmower did not load. Check your connection and try again.', signedOut: false };
    }

    if (isNetworkError(error)) {
        return { title: 'Connection failed', detail: 'Slopmower did not answer. Check your connection and try again.', signedOut: false };
    }

    return { title: 'Something went wrong', detail: error instanceof Error ? error.message : 'An unexpected error occurred.', signedOut: false };
}

/**
 * `embedded` renders inside the root layout, so the error of a screen keeps the header. React Router reports no error
 * that it renders here (D86). The end of a revalidation also clears an error of a render. A code chunk that did not load
 * reloads the page (D106).
 */
export function RouteError({ embedded = false }: { embedded?: boolean }) {
    const error = useRouteError();
    const revalidator = useRevalidator();
    const { title, detail, signedOut } = describeError(error);
    const chunkError = error instanceof Error && chunkErrors.has(error);
    const reloading = error instanceof Error && reloadErrors.has(error);

    useEffect(() => {
        if (reloading) {
            reload();
        } else if (!isRouteErrorResponse(error)) {
            captureException(error);
        }
    }, [error, reloading]);

    if (reloading) {
        return <LoadingPage />;
    }
    const Container = embedded ? 'section' : 'main';

    return (
        <Container className={embedded ? undefined : styles.page} data-testid="route-error">
            <Heading as="h1">{title}</Heading>
            <p>{detail}</p>
            {signedOut ? (
                // The reload lets the firewall store this page as the target of the sign-in (F115).
                <Button variant="primary" onClick={() => window.location.reload()}>
                    Sign in
                </Button>
            ) : (
                <div className={styles.actions}>
                    {isRouteErrorResponse(error) ? null : (
                        <Button loading={'idle' !== revalidator.state} onClick={() => (chunkError ? window.location.reload() : void revalidator.revalidate())}>
                            Try again
                        </Button>
                    )}
                    <Link as={RouterLink} to="/">
                        Go to the start page
                    </Link>
                </div>
            )}
        </Container>
    );
}
