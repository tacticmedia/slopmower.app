# F77 Browser error reporting

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-10-28: "Start showing the data-release attribute somewhere unobtrusive (footer?) and please include Sentry JS client monitoring when data-sentry-dsn is configured, unless this has to be somehow provided at build time. I just want sentry on the react app so that we can track errors, record interactions and so on." Jan's uncommitted `.env`, `config/packages/twig.yaml`, and `templates/base.html.twig` edits are the start.

## Goal

- The SPA shows the release in a footer when `APP_RELEASE` is set.
- With `SENTRY_DSN_FRONTEND` set, the SPA sends its errors, traces, and session replays to Sentry. The DSN, the release, and the environment come from the `<body>` attributes at run time, so the build stays the same for every deployment.
- The browser reports follow the data rules of D67.

## Facts

Verified on 2026-10-28:

- `@sentry/react` 11.9.0 (2026-10-23) is the latest release. Peers `react` 17 to 19 and `react-router` 6 to 8.
- `@sentry/react/react-router` exports `reactRouterBrowserTracingIntegration()`, which takes the router hooks from `react-router`. `wrapCreateBrowserRouter()` returns the plain router when no client has the integration (`reactrouter-compat-utils/instrumentation.js`).
- The SDK option `dataCollection` controls the collected data. `userInfo: true` (the default) lets Sentry infer the IP address. `urlQueryParams: {deny: [...]}` replaces the matching query values with `[Filtered]` in `event.request.url`, the breadcrumbs, and the span attributes.
- Replay takes the network events from the breadcrumbs after `beforeBreadcrumb`, so the filter applies. Replay records the page URLs (`replay_event.urls`, `navigation.push`) without the filter. No SPA page URL has a `q`, `name`, `confirm`, or `code` parameter; only the API calls have them.
- Replay masks all text and inputs and blocks all media by default. It keeps its session in the `sessionStorage` key `sentryReplaySession`.
- React Router renders a loader or render error in the `errorElement` and does not report it. `RouteError` is every `errorElement` of the app.
- `html_attr()` needs `twig/html-extra`, which is not installed.
- The server SDK takes the release from `SENTRY_RELEASE` and the environment from `kernel.environment` (bundle defaults).

## Decisions

- **D86:**
  - One release and one environment for both SDKs: `APP_RELEASE`, and `SENTRY_ENVIRONMENT` with the kernel environment as the fallback. `SENTRY_RELEASE` is no longer read.
  - `SENTRY_DSN_FRONTEND` alone switches the browser SDK on.
  - `dataCollection: {userInfo: false, urlQueryParams: {deny: ['q', 'name', 'confirm', 'code']}}`, the terms of D67.
  - Replay with the masking defaults: 10% of sessions, and every session with an error.
  - Tracing on every page load and navigation. The trace headers go only to the same origin (SDK default).
  - `beforeSend` drops an `ApiError` below 500, as D67 drops a client error. `RouteError` captures every error except a route error response.
- **Release display:** a muted footer below `main` in `RootLayout`, only when `data-release` is set.
- **Template:** plain attributes, with no `html_attr()`.
- **Fixed sample rates.** No configuration for them.

## Depends on

F51 (D67).

## In scope

- `.env`, `.env.prod.dist`: `APP_RELEASE`, `SENTRY_DSN_FRONTEND`, `SENTRY_ENVIRONMENT`.
- `config/packages/twig.yaml`, `config/packages/sentry.yaml`, `templates/base.html.twig`.
- `@sentry/react` 11.9.0, exact pin. `assets/sentry.ts`, `assets/app.tsx`, `assets/router.tsx`, `assets/layout/RouteError.tsx`, `assets/layout/RootLayout.tsx` and its CSS.
- Docs: D86, `docs/deployment/README.md` "Error reporting", `docs/user/privacy.md`, the versions table of `docs/plan/README.md`.

## Out of scope

- Source maps. They need an upload with a Sentry auth token at deployment: Candidate.
- The release on the Twig pages.
- User Feedback, profiling, and logs.

## Acceptance tests

- **Functional** `ShellRouteTest`: the shell `<body>` has `data-release`, `data-sentry-dsn`, and `data-sentry-environment` only when their variables are set, and the environment falls back to the kernel environment.
- **UI** `SentryReports.spec.ts`:
  - A server error of a fetcher sends one event with the release and the environment, no IP inference, and the search text as `[Filtered]`. The footer shows the release.
  - A client error sends no event.

## Verification

`make check`.
