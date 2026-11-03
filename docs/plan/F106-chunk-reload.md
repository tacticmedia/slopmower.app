# F106 Reload when a code chunk fails to load

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "reload the page when a code chunk fails to load". It also closes the risk of the [F102 report](reports/F102.md): "Try again" does not load a failed code chunk again.

## Goal

- When a code chunk does not load, mostly because a deployment removed the chunks of the build that the tab runs, the page reloads and shows the screen that the user opened.

## Facts

Verified on 2026-11-03:

- Vite 8.3.5 wraps each dynamic `import()` of the build in its preload helper. When the import or a preloaded dependency fails, the helper dispatches the cancelable `vite:preloadError` on `window` with the error as `payload`, and throws the error if no listener calls `preventDefault()`. If a listener calls `preventDefault()`, the import resolves to `undefined`.
- The lazy screens of `assets/router.tsx` and the `React.lazy()` components of the screens throw the error into the route error boundary, so `RouteError` shows it and sends it to Sentry.
- The production image holds only the chunks of its own build, so a tab that runs an earlier build gets a failed import for a screen that it did not load yet.
- The dev stack answers a shell path with `Cache-Control: max-age=0, must-revalidate, private`, and a reload revalidates the document, so a reload loads the current build.
- `UnsavedCommentGuard` blocks an unload while a comment box holds unsaved text, so the browser asks before the reload.

## Decisions

- A `vite:preloadError` listener in `RouteError.tsx` records the error. It does not call `preventDefault()`, so the error reaches `RouteError` as before.
- `RouteError` reloads the page, not the listener. React Router sets the address of a navigation after its lazy screen fails, so a reload from the listener loads the previous screen.
- At most one automatic reload per 60 seconds per tab, from a time in `sessionStorage`. The listener decides when the event comes, so a later render of `RouteError` does not change the decision. Without `sessionStorage`, no automatic reload.
- During the reload, `RouteError` shows the loading spinner and sends no Sentry event.
- A chunk that fails again within the 60 seconds shows "Loading failed" and "Part of Slopmower did not load. Check your connection and try again.", and sends the Sentry event. Its "Try again" reloads the page.
- D106 records the reload.

## In scope

- `assets/layout/RouteError.tsx`.
- `tests/ui/ChunkReload.spec.ts`.
- `docs/architecture.md` (D106).

## Out of scope

- A retry of the import without a reload.
- Old chunks in the production image.
- A check of `navigator.onLine` before the reload.

## Acceptance tests

- UI, `ChunkReload`: a click on a request in the list, whose screen chunk fails once, reloads the page, shows that request, and sends no Sentry event.
- UI, `ChunkReload`: a screen chunk that fails also after the reload shows "Loading failed", sends one Sentry event, and "Try again" reloads the page and shows the screen.

## Verification

- `make front` and `make ui` pass.
- Mutation: with the reload in the listener, the first test fails.
