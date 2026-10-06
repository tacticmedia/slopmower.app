# F02 Focus mode

Workflow, conventions, and status: [feature track](features.md).

## Goal

A full-screen focus mode for the code view: only the file tree and the code view are visible, each fills the window height and scrolls on its own, and the page itself does not scroll.

## Facts

- Today the file view has three scroll areas: the window, the file tree (`.tree`, `max-height: 80vh; overflow: auto` in `assets/routes/reviews/CodeLayout.module.css`), and the code view (`.viewport`, `height: 70vh` and `.scroller`, `overflow: auto` in `FileRoute.module.css`). The app header (`RootLayout`) and the review header (`ReviewLayout`: title, agent snippet, `IndexProgress`, tabs) sit above them, so the window scrolls too.
- `@pierre/diffs` `CodeView` scrolls and virtualizes inside its own root element and does not size it. The consumer sets its height.
- The app has no full-screen toggle, no header collapse, and no global key handler. Primer `TreeView` has its own arrow keys.
- Per-view UI state lives in React state, URL search params, or the URL hash. Nothing uses `localStorage` yet.

## Decisions

- In-page full viewport, not the browser Fullscreen API. It needs no permission, works in the Playwright suite, and keeps the browser chrome.
- A "Focus" toggle button in the file header, with `aria-pressed`. Escape leaves focus mode when the focus is not inside a comment box.
- The state lives in `ReviewLayout` and reaches `CodeLayout` and `FileRoute` through React Router's outlet context: no global store.
- The choice survives file navigation and a reload through a per-browser preference in `localStorage`, read and written inside `try`/`catch` with focus off as the fallback. F02 or F03, whichever lands first, adds the one small hook for UI preferences, and the plan README front-end conventions gain one line for it.

## Depends on

M20, M21. No contract change.

## In scope

- Focus on: the app header, the review header, and the closed banner are hidden. The code layout fills `100vh` with the tree column and the file column side by side.
- The file column is a flex column: the file header, then the file-level threads (at most a third of the height, with their own scroll area), then the code view with `flex: 1`.
- The tree and the code view each scroll on their own. The page height equals the window height.
- Line threads, the comment box, the "+" button, line selection, deep links (`#L10-L20`), the Symbols menu, and the Viewed checkbox work unchanged in focus mode.
- At 768 px and below, focus mode keeps the single-column layout of today and only hides the headers.
- `docs/user/reviewing.md`: a short "Focus mode" section.

## Out of scope

- The browser Fullscreen API.
- Changing the normal (non-focus) layout. A single scroll area for the normal file view is a separate candidate.
- Focus mode on the Requests tab and the Changes tab.

## Acceptance tests

- **UI** `tests/ui/FocusMode.spec.ts`:
  - "Focus hides the headers and fills the window": the headers are hidden, and the tree and the code view are inside the viewport. `document.scrollingElement.scrollHeight` equals `window.innerHeight`.
  - "Focus survives navigation and reload": open another file in the tree, then reload. Focus mode stays on.
  - "Escape leaves focus mode": the headers are visible again.
- The existing `LargeFile.spec.ts` geometry check passes with focus on and with focus off.

## Verification

- `make check` is green.
- Manual check: open a large file in focus mode. Scroll the tree and the code view separately; the window does not move. Comment on a line, then leave with Escape.
