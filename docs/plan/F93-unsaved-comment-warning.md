# F93 Warning before leaving an unsaved comment

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "It's too easy to navigate away when a comment is not saved, and then you lose the comment. I want to show an alert when the user is about to navigate to another file while a comment is not saved."

## Goal

While a comment box holds text that is not saved, the app asks before it leaves the page. The user keeps the text, or discards it and goes on.

## Facts

Verified on 2026-11-03:

- `assets/comments/CommentBox.tsx` is the only box that writes a comment body: a new comment (`FileRoute.tsx:292-310`), a reply (`ThreadBox.tsx:127`), a draft edit (`DraftBox.tsx:98-110`), and a new request (`NewRequestBox.tsx:60-71`). The text is `useState` in `CommentBox` (`:65-66`).
- `FileRoute` is not keyed by path (`FileRoute.tsx:46`), so a move to another file unmounts the comment box and loses its text.
- React Router 8.4.0, created with `createBrowserRouter` (`assets/router.tsx:27`). `useBlocker` blocks SPA navigations of a data router. Only one blocker can be active at a time. It does not cover a reload or a closed tab.
- `ReviewLayout` (`router.tsx`, route `reviews/:reviewId`) wraps the code routes and the request routes.
- No `useBlocker` and no `beforeunload` handler exist in `assets/`.
- Primer 38.40.0 exports `ConfirmationDialog`; `assets/settings/DeleteRepositoryDialog.tsx` uses it.
- `bin/patch-react-router.mjs` patches the fetcher redirect of React Router (D82).

## Decisions

- A comment box is unsaved while its title or body differs from its initial values and it is not sending.
- A context in `ReviewLayout` holds the set of unsaved boxes. Each `CommentBox` registers itself while it is unsaved.
- One `useBlocker` in `ReviewLayout` blocks a navigation to another path while the set is not empty. A change of the hash or of the query passes.
- The blocked navigation opens a `ConfirmationDialog`: "Discard your unsaved comment?", with "Discard" (danger, `proceed()`) and "Keep editing" (`reset()`).
- A `beforeunload` listener calls `preventDefault()` while the set is not empty. It covers a reload and a closed tab.

## Depends on

Nothing.

## In scope

- `CommentBox.tsx`, `ReviewLayout.tsx`, and one small context module in `assets/comments/` or `assets/review/`.
- `docs/user/comment.md`.

## Out of scope

- Keeping unsaved text over a navigation (a local store).
- Text boxes other than `CommentBox`: `NoteDialog`, `SubmitFeedbackOverlay`, `CloseReviewDialog`.

## Acceptance tests

UI, `tests/ui/UnsavedComment.spec.ts`:

- Typed text and a click on another file in the tree open the dialog. "Keep editing" keeps the file and the text. "Discard" opens the other file.
- An empty comment box and a click on a line number open no dialog.
- A draft edit with changed text opens the dialog.
- `page.close({ runBeforeUnload: true })` with typed text raises a `beforeunload` dialog.
- A submitted comment does not block the navigation that follows.

## Verification

- `make check` is green.
- Mutation check: without the blocker, the spec fails.
- The patch of `bin/patch-react-router.mjs` does not change blocker behaviour: the specs above pass.
