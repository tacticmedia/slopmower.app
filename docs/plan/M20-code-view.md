# M20 Code view

Workflow, conventions, and status: [README](README.md).

## Goal

Browse the pinned commit with a lazy file tree and a virtualized full-file view, decision D1.

## Depends on

M18.

## In scope

- `npm install --save-exact @pierre/diffs@1.5.1`, an exact pin.
- `assets/code/CodeFile.tsx`, the only wrapper around `CodeView`:
  - `cleanLastNewline`;
  - the Shiki GitHub light and dark themes, with the PHP grammar loaded lazily;
  - the worker pool. If it fails with Vite 8, fall back to the main thread and record that in the report;
  - stable props, so the view does not re-render on each parent render.
- `/reviews/:id/files/*`:
  - the route `loader` reads V7 for the metadata and V8 for the bytes;
  - directories load on expand through a fetcher on V6.
- A Primer `TreeView` for the file tree.
- A file header with the path and a copy button.
- Deep links `#L10` and `#L10-L20`, applied through `selectedLines` and `scrollTo`.
- A placeholder for files that are not renderable: binary files and files above 1 MiB.

## Out of scope

- Comments: M21.
- Viewed files: M25.
- Symbols: M26 and M27.

## Files (representative)

- `assets/code/CodeFile.tsx`, `assets/code/lineHash.ts`, `assets/code/lineHash.test.ts`.
- `assets/review/FileTree.tsx`, `assets/review/FileHeader.tsx`, `assets/routes/reviews/FileRoute.tsx`.
- `tests/Support/E2E/ShadowDom.php`: helpers that reach into open shadow roots with `getShadowRoot()`.
- `tests/Support/Git/FixtureRepositories.php`: a repository with a 3,500-line PHP file, a binary file, and nested directories.
- `tests/E2E/CodeViewTest.php`, `tests/E2E/LargeFileTest.php`, `tests/E2E/BinaryFileTest.php`.

## Tasks (ordered)

1. Install `@pierre/diffs` and write the wrapper.
2. Build the file route, the tree, and the header.
3. Write the deep-link handling.
4. Write the E2E helpers and fixtures, then the tests.

## Acceptance tests

- **V `LineHashTest`** proves the deep-link format.
  - "Parses #L10 and #L10-L20"
  - "Rejects other hashes"
  - "Formats a range as #L10-L20"
- **E `CodeViewTest`** proves the tree and the view.
  - "The tree expands directories on demand"
  - "Line 1 text is readable from the shadow root"
  - "The view has one line-number column"
- **E `LargeFileTest`** proves virtualization.
  - "#L3000 is in the viewport and far fewer than 3,500 rows are rendered"
- **E `BinaryFileTest`** proves the placeholder.
  - "A binary file shows the placeholder instead of code"

## Verification commands

```sh
make front
make e2e
make check
```

## Done

`@pierre/diffs` is used only in `assets/code/CodeFile.tsx`.
