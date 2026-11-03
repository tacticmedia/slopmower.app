# F104 Known issues: request page dialogs, unsaved guard, symbols port race

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "the sticky navbar with the buttons has incorrect z-index, and the modal backdrop is underneath it". The two other issues came up in the same session.

## Goal

- A regression test keeps the dialogs of the request page above its sticky action bar.
- The request page asks before it leaves a comment box with unsaved text, as the review pages do since F93.
- `symbols/src/server.test.ts` does not fail on a port that another process takes.

## Facts

Verified on 2026-11-03 on `main`:

- **Dialog backdrop:** Jan saw it on beta.slopmower.app. On `main`, in the mocked UI build and in the dev app on port 8100, `document.elementFromPoint()` at the action bar with "Close as won't do" open returns the Primer dialog backdrop: `#__primerPortalRoot__` has `position: absolute` and `z-index: 300` (`assets/layout/RootLayout.module.css`, F76), and the bar has `position: sticky` and `z-index: 5` (`assets/requests/ActionBar.module.css`). F76 added that rule on 2026-10-29, so a build before it shows the bug. No test covers the request page; F76 tested the "Submit feedback" dialog against the file header.
- **Unsaved guard:** `UnsavedCommentGuard` (F93) wraps `ReviewLayout`. The request page is the route `requests/:requestId` (`assets/router.tsx`), a sibling of `reviews/:reviewId`, not a child. So its reply box registers with the default context, a no-op, and a navigation from the request page loses typed text without a question.
- **Port race:** the test "SYMBOLS_HOST and SYMBOLS_PORT set the listen address" takes a free port with `freePort()`, closes it, then starts the service on it. In the `make check` of F98 it failed once with "The service stopped before it listened: " and an empty stdout; 3 reruns passed. The error message does not show stderr, so the cause is not recorded; another process taking the port is the likely one.

## Decisions

- No CSS change for the backdrop: `main` is right. A UI test on the request page checks that the backdrop of a dialog covers the action bar. Jan redeploys beta (For Jan).
- `UnsavedCommentGuard` moves up to the layout that holds both routes, or wraps the request route too, so one guard, with its one `useBlocker`, covers the review pages and the request page. React Router allows one blocker per router.
- The symbols test shows stderr in its error and starts again on a new free port when the service reports that the port is in use, at most 3 times.

## Depends on

Nothing.

## In scope

- `tests/ui/` (a request page spec for the backdrop, a request page case in `UnsavedComment.spec.ts`).
- `assets/comments/UnsavedCommentGuard.tsx`, `assets/routes/reviews/ReviewLayout.tsx`, `assets/router.tsx` or the root layout, whichever holds the guard.
- `symbols/src/server.test.ts`.
- `docs/user/comment.md`, if the text names only the review pages.

## Out of scope

- The other dialogs of the app, which share the portal rule.

## Acceptance tests

- UI: with "Close as won't do" open on the request page, the element at the action bar is the dialog backdrop.
- UI: typed text in the reply box of the request page and a click on a link to another page open the dialog of F93.
- Vitest: the symbols server tests pass; the retry is visible in the code, not tested on its own.

## Verification

- `make check` is green.
- Mutation check: without the guard on the request route, the new unsaved case fails.
