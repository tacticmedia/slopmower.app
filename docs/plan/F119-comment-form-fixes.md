# F119 Comment form fixes

Workflow, conventions, and status: [feature track](features.md). From Jan in a session on 2026-11-05.

## Goal

1. A drag selection of lines scrolls the page when the pointer reaches the top or the bottom of the code area, so it can select lines outside the screen.
2. The edit of a pending comment highlights its lines.
3. The title and description fields of a comment box show placeholders, not visible labels.
4. The request options (Type, Group, "Ask for a plan first") are on their own row, above the buttons.
5. The edit of a pending comment can change its line range: "Update selection" next to "Lines X to Y" turns the numbers into inputs, and "Done" applies them. No layout shift.

## Depends on

- F113: the comment box and the edit of a pending comment.

## Facts

Verified on 2026-11-05 in the code, not in a browser:

- `@pierre/diffs` 1.5.2 `InteractionManager`: a press on a line number starts a selection, then `pointermove` on `document` hit-tests `elementFromPoint` at the pointer and calls `preventDefault()`. Nothing scrolls. Outside the viewport the hit test finds no row, so the selection stops at the last visible line. A drag from the gutter "+" works the same way.
- The code scrolls with the window (D55). The sticky file header covers the top, `stickyHeaderPx()`.
- `CodeFile` scrolls to every `selection` that the view did not report, so a range taller than the window moves the comment box below it out of view.
- `DraftBox` edits through D3 and never touches the line selection.
- D3 says: "The anchor cannot change. To move a draft, delete it and create a new one." A delete and create on the client changes the draft ID and its submission order (`createdAt`), and a failed create after the delete loses the draft.

## Decisions

- **Autoscroll:** `CodeFile` runs a frame loop from the start of a selection gesture to `pointerup` or `pointercancel`. In the 48 px band at the bottom of the viewport or below the sticky header, or past them, it scrolls the window, faster the deeper the pointer is. After each step it sends the view a `pointermove` at the pointer, kept inside the code area, so the selection follows.
- **Highlight:** `CodeFile` gets a `highlight` range. It replaces the shown selection and is never scrolled to. The URL hash does not change. FileRoute shows the range of the pending comment that is being edited.
- **Labels:** "Title" and "Description" stay as visually hidden labels, so the accessible names do not change. The placeholders are "Title (optional)" and "Description". The caption "Optional." goes.
- **Range edit:** only in the file view, only for a pending comment with lines, only while its edit form is open. The read text and the inputs share one grid cell, and the link comes after it, so neither the link nor the form moves. The inputs are as wide as the line count of the file has digits. Enter applies, Escape drops the input. `parseLineRange` validates, and its message shows in the error slot of the form. "Update comment" applies inputs that are still open. Cancel drops the new range.
- **D3 `anchor` (D115):** `UpdateDraftInput` gets an optional `anchor`. An absent or null `anchor` keeps the anchor. A new one is captured as in D2. A reply draft refuses it. The new anchor follows the rule of a new comment: an exact symbol range anchors to the symbol (`matchingSymbol`), else `line` or `range`.

## In scope

- `docs/specs/rest-api-requests.md` D3, `docs/specs/schemas/inputs.schema.json` `UpdateDraftInput`, `docs/specs/README.md` change list, `docs/architecture.md` D115.
- `src/Controller/Api/Input/UpdateDraftInput.php`, `src/Controller/Api/DraftController.php`, `src/Entity/Draft.php`.
- `assets/api/types/inputs.ts`, `assets/code/CodeFile.tsx`, `assets/comments/CommentBox.tsx` and its CSS, `assets/comments/DraftBox.tsx`, a line range editor, `assets/routes/reviews/FileRoute.tsx`.
- `tests/ui/support/fakeReview.ts`: D3 with `anchor`.
- `docs/user/comment.md`: the range edit.

## Out of scope

- A range edit outside the edit form, on the Requests tab, or for a file comment.
- A drag in the code that changes the range of a pending comment.
- Autoscroll of a text selection in the code. The browser does it.

## Acceptance tests

- UI: a drag from line 1 to below the bottom of the window scrolls the page and selects lines past the first screen.
- UI: Edit on a pending comment highlights its lines, and Cancel removes the highlight.
- UI: the comment box shows the placeholders "Title (optional)" and "Description" and no visible "Title" or "Description" label.
- UI: "Update selection", new numbers, and "Done" move the highlight and the label; "Update comment" sends D3 with the new anchor. "Update selection" and "Done" keep the position of the link and the height of the header.
- UI: a line number past the end of the file shows the error of `parseLineRange` and keeps the inputs.
- Functional: D3 with `anchor` moves a `new_request` draft and captures the new snippet.
- Functional: D3 with `anchor` on a reply draft answers 422 `invalid_input` for `anchor`.

## Verification

- `make check`.
- Manual, in Chrome on the worktree stack: a drag past the bottom of the window, then the edit of a pending comment with a new range.
