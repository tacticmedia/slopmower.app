# F03 Line wrapping

Workflow, conventions, and status: [feature track](features.md).

## Goal

The code view and the commit diff can wrap long lines instead of scrolling horizontally.

## Facts

- `@pierre/diffs` 1.5.1 has `overflow?: 'scroll' | 'wrap'` on its base options, default `'scroll'`. `CodeView` passes it to file and diff items. In wrap mode the rows get `white-space: pre-wrap; word-break: break-word`.
- Changing `overflow` at runtime does not remount. `CodeView.setOptions()` treats it as a layout change and re-renders.
- In wrap mode the library measures every row instead of estimating its height. That is the cost for large files.
- `assets/code/CodeFile.tsx` and `assets/code/CommitDiff.tsx` set no `overflow` today. `@pierre/diffs` may be used only inside these two files (plan README).
- `CodeFile.tsx` holds the fix for the blank-view bug of `@pierre/diffs` (a memoized file object; annotation changes held until the first highlighted render). The fix must stay intact.

## Decisions

- A "Wrap lines" toggle in the file header, with `aria-pressed`. One choice serves the code view and the commit diffs of the Changes tab.
- The choice is a per-browser preference in `localStorage`, the same hook as F02 (whichever lands first adds it). The default is off, as today.
- `CodeFile` and `CommitDiff` take an `overflow` prop. The options object stays memoized, so that only a real change calls `setOptions`.

## Depends on

M20, M30. No contract change.

## In scope

- The toggle, the preference, and the `overflow` prop in both wrappers.
- Line selection, the "+" button, keyboard commenting, deep links, `scrollTo` for a range, and line annotations work on wrapped lines.
- `docs/user/reviewing.md`: one sentence on "Wrap lines".

## Out of scope

- Soft wrap at a fixed column, or per-file settings.
- A wrap setting stored on the server.

## Acceptance tests

- **UI** `tests/ui/LineWrap.spec.ts`:
  - "Wrap lines removes horizontal scrolling": a file with a 400-character line. With wrap on, the code view's `scrollWidth` is at most its `clientWidth`.
  - "A wrapped line takes a comment": select a wrapped line through its line number and open the comment box. The request body sent has the expected anchor range.
  - "The choice applies to the Changes tab and survives a reload".
- `LargeFile.spec.ts` passes with wrap on. If its time budget fails, record the measured cost and the decision in the report.

## Verification

- `make check` is green.
- Manual check: turn on "Wrap lines" on a file with long lines, comment on a wrapped line, and open the Changes tab.
