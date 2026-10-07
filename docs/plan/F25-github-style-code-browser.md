# F25 GitHub-style code browser

Workflow, conventions, and status: [feature track](features.md). Jan's request of 2026-10-08: the F21 file view is bad UI; the code browser works like GitHub's repository file browser.

## Goal

The Code tab uses the full window width, and the window scrolls. The app header, the review header, and the tabs scroll away. The tree pane is sticky with the window height, and the code has the height of the code. When the headers are gone, a sticky file header shows the path, "Top", and the file commands. Without an open file, the directory shows as a table. Focus mode goes, because the scrolled state is the same.

## GitHub reference

Measured on public pages of `symfony/symfony` at 1440x900, signed out, with the `chrome-devtools` MCP server (DOM, computed styles, scroll behaviour):

- **Layout:** Primer `PageLayout` (`prc-PageLayout-*` classes, as in `@primer/react` 38.40.0), full width. The global header and the repository header with its tabs are not sticky.
- **Tree pane:** `PageLayout.Pane` with `sticky` (`position: sticky; top: 0; max-height: 100vh`) and `resizable` (slider "Draggable pane splitter", 256 to 481 px, default 320). The tree list has `overflow: auto`. The pane header: "Files", "Collapse file tree", branch picker, search, "Go to file". Below about 1012 px the pane hides, and "Expand file tree" moves into the file header. The current file is scrolled into view.
- **Code:** the window scrolls. The code has its full height (2,343 lines x 20 px). The rows are virtualized against the window: 438 of 2,343 rows exist.
- **Sticky file header:** a zero-height `position: sticky; top: 0; z-index: 4` element with an absolutely positioned box, so its height changes do not move the code.
  - Toolbar (46 px): "Code | Blame", "2343 lines (2021 loc) · 79.1 KB"; "Raw", "Copy raw file", "Download raw file", edit buttons, "Open symbols panel".
  - When stuck, a 44 px row above the toolbar: breadcrumbs with a link per segment, "Copy path", "Top".
  - Below the toolbar: the enclosing class and method lines of the top visible line ("sticky lines").
  - The horizontal scrollbar of the code is a proxy, sticky at `bottom: 0`.
- **Above the file box:** breadcrumbs with "Copy path", then a last-commit box.
- **Line link** `#L372-L380`: the range lands below the sticky header, highlighted, with a "Line 372 options" menu.
- **Directory view:** breadcrumbs ending with "/", a last-commit box, a table "Folders and files" (Name, Last commit message, Last commit date) with a ".." row and directories first, then the README. When stuck: breadcrumbs, "Copy path", "Top".

## Decisions

Jan, 2026-10-08:

- **Focus mode goes** (F02, F23): `FocusToggle`, the shortcut Cmd+Shift+Enter or Ctrl+Shift+Enter, the `focus` preference, `data-focus-mode`, and their tests and docs. "Collapse file tree" gives the width.
- **Directory table:** Name, Size, Viewed. Last-commit columns are a candidate: they need a new endpoint and `git log` per entry.
- **In this feature:** the layout, the directory view, the README under the table, the sticky scope lines, and "Raw", "Copy raw file", "Download raw file".
- **Blame view** is [F26](F26-blame-view.md), after this feature.

Design:

1. **Window-scroll virtualization (D55).** `@pierre/diffs` 1.5.2 `CodeView` virtualizes only inside its own element (F21). `Virtualizer` supports the window: `setup(root: HTMLElement | Document)` calls `setupWindow()` and reads `window.scrollY` (`dist/components/Virtualizer.js`). The library docs (Virtualization, React): the React wrapper scrolls with the window only through `VirtualizerContext.Provider` with a `Virtualizer` instance that the app creates. So `CodeFile` renders React `File` inside `VirtualizerContext.Provider`, with one `Virtualizer` set up on `document`. React `File` then creates a `VirtualizedFile` (`dist/react/utils/useFileInstance.js`).
2. **Scroll to a line.** React `File` does not expose its instance. `FileOptions.onPostRender(node, instance, phase)` passes it, and `VirtualizedFile.getLinePosition(lineNumber)` gives `{top, height}` in the file. The target is the file top plus the line top, less the sticky header height, centred in the space below the header, through `window.scrollTo()`.
3. **Spike first, as a gate** (step 1). It proves every `CodeFile` feature under the window `Virtualizer`. If one fails, stop and report to Jan. No fallback that steals wheel events or turns off virtualization.
4. **Layout:** Primer `PageLayout` in the Code tab, `containerWidth="full"`. `PageLayout.Pane position="start" sticky resizable widthStorageKey="holistic-review.tree-width"`, width `{min: '256px', default: '320px', max: '640px'}`. `main` has no `max-width` on the Code tab.
5. **Breakpoint stays at 768 px** (Primer `regular`, where `resizable` starts). At 768 px and below, the pane stacks above the content, is not sticky, and keeps `max-height: 40vh`.
6. **Collapse file tree:** a button in the pane header. The `usePreference` key `'focus'` becomes `'treeCollapsed'`. While the tree is collapsed, "Expand file tree" is the first control of the breadcrumb row.
7. **Sticky file header, GitHub's technique:** a zero-height sticky wrapper with an absolutely positioned box, a reserved height for the toolbar, and an `IntersectionObserver` on the breadcrumb row that sets the stuck state. The stuck row holds the breadcrumbs, "Copy path", and "Top".
8. **Toolbar:** left, "N lines · size" from `FileContent.lineCount` and `sizeBytes`. Right, the file commands (Go to declaration, Wrap lines, Comment on lines, Symbols, Comment on file, Viewed), then a `ButtonGroup`:
   - "Raw": a link to V8 `rawUrl` in a new tab. V8 sends `text/plain`, `nosniff`, and a sandbox CSP.
   - "Copy raw file": the loaded text, through `CopyButton`.
   - "Download raw file": `<a download>`, same origin.

   "Copy path" moves next to the breadcrumbs.
9. **File-level threads** sit between the toolbar and the code and flow with the page. Their `max-height` goes.
10. **Routes:** the Code tab index `/reviews/:reviewId` shows the root directory. `/reviews/:reviewId/tree/*` shows a subdirectory. The loader-only route `tree` (`?dir=`, the tree fetchers through `treeHref()`) becomes `tree-entries`, so `tree` and `tree/*` cannot collide.
11. **Tree:** a directory label opens its directory view, and its chevron only toggles. The open file or directory is `current`, and the pane scrolls it into view (`block: 'nearest'`).
12. **README:** below the table when the directory has a `README.md` (any letter case). The text comes from V8 and renders with `MarkdownBody` (react-markdown, remark-gfm) at README size. Relative links go to `files/*` or `tree/*`, and relative images to V8 `raw?path=`.
13. **Sticky scope lines:** for a file with V9 symbols. The class-like and function symbols with `declarationStartLine < top line <= range.endLine`, outermost first, show the text of their `declarationStartLine` in monospace with the line number, below the toolbar. A click scrolls to that line. `useFileSymbols` loads for every renderable file, also on a closed review.
14. **Differences from GitHub:** no textarea overlay, no last-commit box, no line options menu, no sticky horizontal scrollbar. The horizontal scrollbar of the code stays at the file bottom (`[data-code]{overflow: scroll clip}`), as in the current view.

## Depends on

F21 (merged). F02 and F23 are removed.

## In scope

- Front end:
  - `assets/code/CodeFile.tsx`;
  - `assets/layout/RootLayout.module.css`, `assets/layout/usePreference.ts`;
  - `assets/routes/reviews/ReviewLayout.tsx` and its CSS module, `CodeLayout.tsx` and its CSS module, `FileRoute.tsx` and its CSS module, `treeData.ts`;
  - `assets/router.tsx`;
  - `assets/review/FileHeader.tsx`, `assets/review/FileTree.tsx`, `assets/review/QuickOpen.tsx` (comment only);
  - new: a `Breadcrumbs` component, a `DirectoryRoute` with `directoryLoader`, the README block, the sticky scope lines.
- UI tests and their support code, and Vitest for the pure helpers.
- Docs:
  - `docs/architecture.md`: section 2 row "File view", section 8 row "Review", section 11 D55;
  - `docs/user/reviewing.md`: "Focus mode" becomes "Code browser", and the Quick open notes;
  - `docs/developer-guide.md`: counts;
  - `docs/plan/README.md`: gotchas.

## Out of scope

- Blame: [F26](F26-blame-view.md).
- Last-commit columns and the last-commit box, the line options menu, the sticky horizontal scrollbar, single-child directory chains as one row, a Markdown preview of a `.md` file, "Finish review" in the stuck row: [Candidates](features.md#candidates).
- Back-end and contract changes. V6, V7, V8, and V9 serve this feature as they are.

## Steps

Each step ends with `make check` green. A session may stop at a step boundary and report.

1. **Spike (gate).** Replace `CodeView` with `File` in a document-mode `Virtualizer`. In the dev stack, with this repository as a local repository, prove on `src/Mcp/BundledSchemas.php` (3,257 lines):
   - the worker pool highlights the code, and fewer than 300 rows render at a time;
   - controlled line selection, the gutter "+" with the pointer and the keyboard (D31), inline threads and the composer, Cmd-click on tokens, and Wrap lines;
   - `#L3000` lands below the sticky header on the first load, after a wrap toggle, and after a change of file;
   - a thread that opens above the viewport does not move the visible lines;
   - the instance and the virtualizer clean up on a change of file and on unmount.
2. **Layout:** decisions 4 to 6, and the removal of focus mode.
3. **File view:** decisions 2, 7 to 9.
4. **Directory view:** decisions 10 and 11.
5. **README:** decision 12.
6. **Sticky scope lines:** decision 13. The top visible line comes from the `VirtualizedFile` instance (`getNumericScrollAnchor`) if step 1 shows that it fits. Otherwise it comes from `shadowRoot.elementFromPoint()` below the sticky header, throttled with `requestAnimationFrame`.
7. **Docs.**

## Acceptance tests

Mocked UI suite at 1280x720, unless a test names another size.

- **UI `CodeBrowserLayout.spec.ts`**, which replaces `FileViewLayout.spec.ts` and `FocusMode.spec.ts`:
  - the code layout spans the window width less the padding of `main`;
  - with a long file, the window scrolls. After a scroll past the headers: the pane top is 0 and its height is `innerHeight`, the review header is out of the window, the toolbar top is 0, and the stuck row shows "Top";
  - "Top" scrolls to 0, and the stuck row hides;
  - the tree scrolls on its own (60 more root files);
  - the pane splitter changes the width with the arrow keys, and the width stays after a reload;
  - "Collapse file tree" and "Expand file tree" work, and the state stays after a reload;
  - at 700 px width the layout is one column, and the pane is not sticky.
- **UI `DirectoryView.spec.ts`:**
  - the Code tab index shows the root table with names, sizes, and the viewed mark, and no "..";
  - a directory row opens `tree/src` with ".." and the breadcrumbs, and ".." goes up;
  - a file row opens the file;
  - a breadcrumb segment of a file opens its directory;
  - a directory label in the tree opens its directory, and its chevron only toggles;
  - the README renders, with a relative link and a relative image rewritten.
- **UI `FileToolbar.spec.ts`:** the line count and the size; the `href` and `target` of "Raw"; the `download` attribute of "Download raw file"; "Copy raw file" puts the text on the clipboard; "Copy path" is next to the breadcrumbs.
- **UI `StickyScopeLines.spec.ts`:** after a scroll into a method of a PHP file, the class line and the method line show below the toolbar, and a click scrolls to the method declaration. A file without symbols shows no scope lines.
- **Changed:**
  - `LargeFile.spec.ts`: no focus variants; line 3000 is in the window and below the toolbar;
  - `KeyboardCommentOnLines.spec.ts`: the window is the scroller;
  - `support/codeView.ts` `dragFromPlus()`: still centres the line and passes the pointer test;
  - `QuickOpen.spec.ts`: the focus-mode test goes;
  - `CodeView.spec.ts`, `CodeNavigation.spec.ts`, `Viewed.spec.ts`: only where the tree or the Code tab index changed.
- **Vitest:** the breadcrumb segments of a path; the scroll target from the line position, the sticky header height, and the viewport; the enclosing scope symbols of a line; the README link and image rewrite.

## Verification

- The spike result is in the report before step 2.
- `make check` is green.
- Manual check in the dev stack at 1280x720 and 1920x1080, with the `chrome-devtools` MCP server, against the GitHub reference above:
  - on `src/Mcp/BundledSchemas.php`, a scroll moves the headers out; the pane sticks with the window height; the stuck row appears and the code does not jump;
  - `#L1490` lands below the toolbar; the gutter "+" and a thread work far down the file;
  - the Code tab index and `tree/src` show their tables, and the README of the root renders;
  - the collapsed state and the width of the tree stay after a reload;
  - at 700 px the layout is one column.
