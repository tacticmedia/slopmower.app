# F102 Retry on the error screen

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "add the retry button to the error screen", the Candidate "Retry after a network error" from F101.

## Goal

- After a failed load, the error screen offers "Try again", which loads the screen again without a page reload.

## Facts

Verified on 2026-11-03:

- `assets/layout/RouteError.tsx` renders every `errorElement`: a title, a detail, and "Sign in" for a 401 or "Go to the start page" for every other error.
- No code of `assets/` throws a `Response`, so a route error response is the 404 of an unknown path.
- `useRevalidator().revalidate()` runs the loaders of the current location again. `RenderErrorBoundary` of React Router 8.4.0 (`dist/development/lib/hooks.js`) takes the error of the router state again when a revalidation returns to `idle`, so a revalidation also clears an error of a render.
- `React.lazy()` keeps a failed import, so a revalidation does not load a failed code chunk again.

## Decisions

- "Try again" shows for every error but a 401 and a route error response. It calls `revalidate()` and shows the loading state of the button while the revalidation runs.
- A failed revalidation shows the error screen again, with the new error, and reports it as any other.
- No page reload. When the connection is still down, the screen stays in the app.

## Depends on

[F101](F101-network-errors.md), which adds the Candidate.

## In scope

- `assets/layout/RouteError.tsx`, `assets/layout/StatusPage.module.css`.
- UI tests.

## Out of scope

- A text for a network error in place of the message of the browser: Candidate.
- A reload after a failed code chunk.

## Acceptance tests

- UI: "Try again after a request without a response shows the screen". The first load of `/repositories` is aborted, "Try again" shows the repository.
- UI: the screens of a 401 and of an unknown path show no "Try again".

## Verification

- `make front` and `make ui` pass.
- Mutation: with a no-op "Try again", the first acceptance test fails.
