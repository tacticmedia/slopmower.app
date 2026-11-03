# F103 Network error text on the error screen

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "show plain text for network errors on the error screen", the Candidate "Network error text on the error screen" from F102.

## Goal

- After a `fetch()` without a response, the error screen says that the connection failed, not the message of the browser.

## Facts

Verified on 2026-11-03:

- `RouteError` shows "Something went wrong" and `error.message` for an error that is no `ApiError` and no route error response. With the SDK on, a network error reads for example `Load failed (slopmower.app)`.
- `assets/sentry.ts` holds the network error messages of D105 and the check in `isReported()`.

## Decisions

- `isNetworkError()` in `assets/api/client.ts`, next to `ApiError`, holds the messages and the check of D105. `assets/sentry.ts` and `RouteError` use it.
- For a network error, `RouteError` shows the title "Connection failed" and the detail "Slopmower did not answer. Check your connection and try again." "Try again" of F102 stays.
- The D105 row names the screen text.

## Depends on

[F101](F101-network-errors.md) and [F102](F102-retry-on-the-error-screen.md).

## In scope

- `assets/api/client.ts`, `assets/api/client.test.ts`, `assets/sentry.ts`, `assets/layout/RouteError.tsx`.
- `tests/ui/RouteError.spec.ts`, `tests/ui/SentryReports.spec.ts`.
- `docs/architecture.md` (D105).

## Out of scope

- Other error texts.

## Acceptance tests

- Vitest, `ApiClientTest`: `isNetworkError()` is true for each message of D105, with and without the host suffix, and false for a failed dynamic import, another `TypeError`, and an `ApiError`.
- UI, `RouteError`: after an aborted load, the error screen shows "Connection failed" and the detail, and not "Failed to fetch".

## Verification

- `make front` and `make ui` pass.
