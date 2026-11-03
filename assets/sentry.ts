import { feedbackIntegration, init, replayIntegration } from '@sentry/react';
import { reactRouterBrowserTracingIntegration } from '@sentry/react/react-router';
import { ApiError } from './api/client';

const { sentryDsn, sentryEnvironment, release } = document.body.dataset;

/** The `TypeError` messages of a `fetch()` without a response, in Chrome, Firefox, Safari 17, and Safari 16. */
const NETWORK_ERROR_MESSAGES = new Set(['Failed to fetch', 'network error', 'NetworkError when attempting to fetch resource.', 'Load failed', 'The Internet connection appears to be offline.']);

/** D86, D105. The SDK appends ` (<host>)` to a network error message. */
function isReported(error: unknown): boolean {
    if (error instanceof ApiError) {
        return error.status >= 500;
    }

    return !(error instanceof TypeError && NETWORK_ERROR_MESSAGES.has(error.message.replace(/ \([^()]+\)$/, '')));
}

// D86, D87. Runs before ./router, because wrapCreateBrowserRouter() needs the initialized client.
if (sentryDsn) {
    init({
        dsn: sentryDsn,
        environment: sentryEnvironment,
        release,
        integrations: [reactRouterBrowserTracingIntegration(), replayIntegration(), feedbackIntegration()],
        tracesSampleRate: 1.0,
        replaysSessionSampleRate: 0.1,
        replaysOnErrorSampleRate: 1.0,
        // The terms of `url_query_params` in config/packages/sentry.yaml.
        dataCollection: { userInfo: false, urlQueryParams: { deny: ['q', 'name', 'confirm', 'code'] } },
        beforeSend: (event, { originalException }) => (isReported(originalException) ? event : null),
    });
}
