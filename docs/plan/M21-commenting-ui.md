# M21 Commenting UI

Workflow, conventions, and status: [README](README.md).

## Goal

Comment on a line, a range, or a file, with the mouse or the keyboard, and show threads inline. Decisions D2 and D31.

## Depends on

M13, M20.

## In scope

- The gutter "+" button. Drag and shift-click select ranges. The comment box opens below the bottom line in both drag directions.
- The glue from the phase 1 spike, see [spike-results.md](../architecture/spike-results.md):
  - wait for the annotation slot before focus;
  - clear the selection after submit or cancel, finding G1;
  - one annotation per line;
  - a stable annotations array;
  - the `BaseStyles` wrapper inside the annotation slot.
- `ThreadBox` built from Primer `Avatar`, `RelativeTime`, `Label`, `Textarea`, `Button`, and `ActionMenu`, plus a CSS module.
- Markdown through `react-markdown` and `remark-gfm`, styled by `markdown.css` of `@primer/css`. Raw HTML is shown as text.
- "Add single comment" posts Q2 through `useFetcher`. The thread shows at once through `useOptimistic`.
- A reply box that posts Q4, also with `useOptimistic`.
- Keyboard access, decision D31. The library's gutter is not changed:
  - a focusable "Comment on file" button in the file header creates a `file` anchor;
  - a "Comment on lines" command in the file header with start and end line fields. It selects the range through `selectedLines` and opens the comment box below the last line;
  - the comment box receives focus.
- Check whether the `@pierre/diffs` "+" button is reachable and usable by keyboard. Record the result in the report and in the "Differences from the GitHub PR UI" table of docs/architecture.md section 8.

## Out of scope

- Drafts: M25.
- The Symbols menu: M27.
- Status actions: M22.

## Files (representative)

- `assets/comments/CommentBox.tsx`, `ThreadBox.tsx`, `ThreadList.tsx`, `MarkdownBody.tsx`, `CommentOnLinesForm.tsx`, with CSS modules.
- `assets/code/annotations.ts`, `assets/code/rangeSelection.ts`, `assets/comments/lineRangeInput.ts`, and their `*.test.ts` files.
- `tests/E2E/LineCommentTest.php`, `RangeCommentTest.php`, `FileCommentTest.php`, `ReplyAndMarkdownTest.php`, `KeyboardCommentOnLinesTest.php`.

## Tasks (ordered)

1. Write the annotation and range-selection glue.
2. Build `ThreadBox`, `ThreadList`, `MarkdownBody`, and `CommentBox`.
3. Wire "Add single comment" and the reply box.
4. Build the "Comment on file" button and the "Comment on lines" form.
5. Probe the keyboard behaviour of the "+" button and record the result.
6. Write the tests.

## Acceptance tests

- **V `AnnotationsTest`** proves the annotation glue.
  - "Groups threads per line"
  - "Keeps the array identity when nothing changed"
  - "Finds the bottom line for both drag directions"
- **V `LineRangeInputTest`** proves the "Comment on lines" validation.
  - "Rejects a start after the end and lines outside the file"
- **E `LineCommentTest`** proves a line comment.
  - "A comment through + on line 10 is saved and shows on line 10 after a reload"
- **E `RangeCommentTest`** proves range selection.
  - "A drag from 5 to 9, a drag from 9 to 5, and click with shift-click all give the anchor 5 to 9"
- **E `FileCommentTest`** proves a file comment.
  - "The file comment button creates a file anchor, also on a binary file"
- **E `ReplyAndMarkdownTest`** proves replies and safe Markdown.
  - "A reply appears in the thread"
  - "**bold** renders as strong and a script tag shows as text"
- **E `KeyboardCommentOnLinesTest`** proves keyboard-only commenting. It uses no mouse action.
  - "Comment on lines 3 to 4 creates a range request with the keyboard only"
  - "The comment box has focus when it opens"
  - "The file comment button works with the keyboard"
  - "The focus behaviour of the gutter + button equals the recorded result"

## Verification commands

```sh
make front
make e2e
make check
```

## Done

- Line, range, and file anchors can be created in the UI.
- The report and docs/architecture.md section 8 record whether the "+" button is keyboard accessible.
