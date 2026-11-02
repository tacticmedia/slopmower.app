# F94 File tree entries as links

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "It's not possible to cmd-click or right-click on a file in the file browser and open it in a new tab or window, enable this."

## Goal

A file or a directory in the file tree opens in a new tab or window with Cmd-click, Ctrl-click, Shift-click, a middle click, or the browser context menu. A plain click still opens it in place.

## Facts

Verified on 2026-11-03:

- `assets/review/FileTree.tsx:87` (directory) and `:117` (file) navigate with `TreeView.Item onSelect` and `navigate()`. The items have no `href`.
- Primer 38.40.0, `node_modules/@primer/react/dist/TreeView/TreeView.js`:
  - `TreeView.Item` takes `as` (`:122-124`). A polymorphic item renders its element with `role="treeitem"` inside `<li role="none">` (`:211`, `:294`) and passes other props, `href` too, to the element (`:205`).
  - `onSelect` runs on click (`:228-231`), on Enter and Space (`:172-179`), and on a middle click (`:233-236`).
- The directory table of `DirectoryRoute.tsx:50,99` already uses `Link as={RouterLink}`.
- URLs come from `assets/review/fileHref.ts` (`fileHref`, `directoryHref`).
- Tests select tree items with `[data-testid="file-tree"] [data-path="..."]` (`tests/ui/DirectoryView.spec.ts:59-71`, `CodeView.spec.ts:23-38`).

## Decisions

- Files and directories render `TreeView.Item as="a"` with `href`. A submodule has no link.
- One `onSelect` for both:
  - A click with Meta, Ctrl, Shift, or Alt, or with a button other than the main one, returns without `preventDefault()`, so the browser opens the link.
  - Enter with Meta or Ctrl does the same.
  - Otherwise it calls `event.preventDefault()` and `navigate(href)`. On Enter, `preventDefault()` also stops the native link activation.
- The directory chevron still only toggles.

## Depends on

Nothing.

## In scope

- `FileTree.tsx` and its CSS module, if the anchor needs a reset of the link colour or underline.
- `docs/user/browse-code.md`.

## Out of scope

- Quick open, "Go to declaration", and other navigations through `navigate()`.

## Acceptance tests

UI, in `tests/ui/CodeView.spec.ts` or `DirectoryView.spec.ts`:

- The `href` of a file item is its file URL, and that of a directory item is its directory URL.
- A Ctrl or Meta click and a middle click open a new page (`context.waitForEvent('page')`) at that URL, and the current page keeps its URL.
- A plain click and Enter still navigate in place.

## Verification

- `make check` is green.
- The `CodeBrowser` journey capture shows the tree unchanged.
