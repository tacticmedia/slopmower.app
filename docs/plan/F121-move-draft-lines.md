# F121 Update selection without the edit form

Workflow, conventions, and status: [feature track](features.md). From Jan in a session on 2026-11-05, after [F119](F119-comment-form-fixes.md).

## Goal

- A pending comment on lines in the file view shows "Update selection" next to its lines also when its edit form is closed. "Done" saves the new lines at once.

## Depends on

- F119: `LineRangeEditor`, the `highlight` of `CodeFile`, and the optional `anchor` of D3 (D115).

## Facts

Verified on 2026-11-05 in the code:

- `DraftBox` shows `LineRangeEditor` only while the edit form is open, and **Update comment** sends the anchor with the text.
- D3 replaces `title`, `body`, and `group` together, and keeps `requireApproval` and `type` when they are absent.
- In read mode the header ends with the menu button, which has `margin-left: auto`, so a wider or narrower link does not move it.

## Decisions

- Read mode: "Done" or Enter sends D3 with the stored `title`, `body`, and `group` and the new `anchor`, and no `requireApproval` or `type`. Unchanged lines send nothing.
- The new lines show at once. A failed D3 shows its error below the comment and the stored lines again.
- While the inputs are open, the code highlights the stored lines, as in the edit form.
- The same pending comments as in F119: the file view, lines, an open review, and a draft that D2 has saved.
- D115 says that the header of a pending comment changes its lines, with or without the edit form. No new decision number.

## In scope

- `assets/comments/DraftBox.tsx`, `assets/comments/LineRangeEditor.tsx`.
- `tests/ui/CommentForm.spec.ts`.
- `docs/user/comment.md`, `docs/architecture.md` D115.

## Out of scope

- The Requests tab, a comment on a file, and a closed review.

## Acceptance tests

- UI: without the edit form, "Update selection", new numbers, and "Done" send D3 with the stored title, body, and group and the new anchor, and the comment moves below its new last line.
- UI: while the inputs are open, the code highlights the lines of the comment.

## Verification

- `make front` and `make ui` in the worktree. `check-main.sh` after the merge runs every gate.
