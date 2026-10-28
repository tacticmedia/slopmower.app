import { init, replayIntegration } from '@sentry/react';
import { reactRouterBrowserTracingIntegration } from '@sentry/react/react-router';
import { ApiError } from './api/client';

const { sentryDsn, sentryEnvironment, release } = document.body.dataset;

// D86. Runs before ./router, because wrapCreateBrowserRouter() needs the initialized client.
if (sentryDsn) {
    init({
        dsn: sentryDsn,
        environment: sentryEnvironment,
        release,
        integrations: [reactRouterBrowserTracingIntegration(), replayIntegration()],
        tracesSampleRate: 1.0,
        replaysSessionSampleRate: 0.1,
        replaysOnErrorSampleRate: 1.0,
        // The terms of `url_query_params` in config/packages/sentry.yaml.
        dataCollection: { userInfo: false, urlQueryParams: { deny: ['q', 'name', 'confirm', 'code'] } },
        beforeSend: (event, { originalException }) => (originalException instanceof ApiError && originalException.status < 500 ? null : event),
    });
}
