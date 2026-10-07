# F21 File view fills the window

Workflow, conventions, and status: [feature track](features.md). The candidate "One scroll area in the normal file view", split from F02.

## Goal

Outside focus mode, the file view no longer scrolls the window. The headers stay visible, and the file tree and the code view fill the height below them and each scroll on their own, as in focus mode.

## Decisions

- **Two scroll areas, not one.** `@pierre/diffs` 1.5.1 `CodeView` virtualizes inside its own root element: `setup(root)` listens to the root's scroll events, reads its `scrollTop` and height, and has no window mode (`components/CodeView.js`, checked in the installed source). Without a bounded root height it renders every row. So the code view keeps its own scroll area, and the window is the one that stops scrolling. This is the F02 focus-mode model with the headers kept.
- **Layout above 768 px, on the file route only** (`/reviews/:reviewId/files/*`):
  - The page is a flex column of the window height: the app header, the review header and tabs, then the code layout with `flex: 1` and `min-height: 0`.
  - The code layout keeps its grid of tree column and file column, with one row of `minmax(0, 1fr)`; the tree loses `max-height: 80vh`, and the code view loses `height: 70vh` and takes `flex: 1`, as in focus mode.
  - The file-level threads take at most a third of the file column and scroll, as in focus mode.
  - The code layout keeps a minimum height of `20rem`. In a window too low for the headers and that minimum, the window scrolls, as the fallback.
- **Focus mode** becomes "the file view layout without the headers": it keeps hiding the headers and the `main` padding, and shares the height rules instead of repeating them.
- **At 768 px and below** the layout stays as it is: one column, and the window scrolls.
- Other tabs (Requests, Changes) do not change.

## Depends on

F02 (merged). F23 (focus mode shortcut, run by the second orchestrator) touches the focus-mode hook next to this layout; whichever merges second rebases.

## In scope

- `assets/layout/RootLayout.module.css`, `assets/routes/reviews/ReviewLayout.tsx` and its CSS module, `CodeLayout.tsx` and `CodeLayout.module.css`, `FileRoute.tsx` and `FileRoute.module.css`. The file route marks the page, for example with a `data-file-view` attribute next to `data-focus-mode`, so the CSS can bound the height without a global rule.
- Docs: architecture section 8 Review row, `docs/user/reviewing.md` (the file view paragraph, if it names scrolling), the developer guide only if a count changes.

## Out of scope

- Making the code view scroll with the window, which `CodeView` does not support.
- A sticky header or tree.
- The layout at 768 px and below.

## Acceptance tests

- **UI** `FileViewLayout.spec.ts`, with focus off at the default 1280x720 viewport:
  - the review header and the tabs are visible;
  - the tree and the code view are inside the window, and their bottoms align;
  - the tree scrolls when it is taller than its column (60 root files, as `FocusMode.spec.ts` seeds them);
  - `document.scrollingElement.scrollHeight` equals `window.innerHeight`.
- `FocusMode.spec.ts` and `LargeFile.spec.ts` (both focus states) stay green unchanged.

## Verification

- `make check` is green.
- Manual check: open a long file of this repository in the dev app with focus off; scroll the tree and the code view; the window does not move, and the headers stay in place.
