# F101 Network errors in browser reports

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "drop network errors in beforeSend", the Candidate "Network errors in browser reports" from F99. The report that started F99 was `TypeError: Load failed` from `fetch()` in `assets/api/client.ts:92`.

## Goal

- A `fetch()` that gets no response sends no browser report.
- Every other error keeps its report, also a failed load of a code chunk.

## Facts

Verified on 2026-11-03:

- `assets/sentry.ts`: `beforeSend` drops an `ApiError` below 500 and keeps every other event.
- A network error of a route loader rejects with a `TypeError`. `actionError()` passes it on, so `RouteError` shows it and calls `captureException()`. `RouteError` shows `error.message`.
- The browsers give the `TypeError` of a `fetch()` without a response these messages. The list is that of `is-network-error` 1.3.2 (`index.js`), limited to browsers:
  - Chrome: `Failed to fetch`, or `Failed to fetch (<host>)`, and `network error` when the body read fails.
  - Firefox: `NetworkError when attempting to fetch resource.`
  - Safari 17 and later: `Load failed`, or `Load failed (<host>)`. Safari 16: `The Internet connection appears to be offline.`
- Chromium gives `Failed to fetch` for a request that Playwright aborts with `route.abort('failed')`.
- A failed dynamic import has another message, for example `Failed to fetch dynamically imported module: <url>` in Chrome.

## Decisions

D105:

- `beforeSend` also drops a `TypeError` whose message is one of the messages above, with or without the host suffix. The match is exact, so a failed dynamic import keeps its report.
- The error screen does not change. A retry on it stays a Candidate.

## Depends on

Nothing.

## In scope

- `assets/sentry.ts`.
- `tests/ui/SentryReports.spec.ts`.
- `docs/deployment/README.md` (browser reports), `docs/architecture.md` (D105).

## Out of scope

- A retry on the error screen.
- Server events.

## Acceptance tests

- UI, `SentryReports`: "A request that gets no response sends no event, and a server error after it sends one". The first load of `/repositories` is aborted, the error screen shows `Failed to fetch`, "Go to the start page" loads the list again, the API answers 500, and the only event is that of the 500.

## Verification

- `make front` and `make ui` pass.
- Mutation: without the new condition in `beforeSend`, the acceptance test fails.
