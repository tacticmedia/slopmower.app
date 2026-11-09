# F123 No gap before "Update selection"

Workflow, conventions, and status: [feature track](features.md). From Jan in a session on 2026-11-09: "tighten the gap before Update selection". It is the follow-up of the [F121 report](reports/F121.md).

## Goal

- On a pending comment on lines, "Update selection" comes directly after "Lines X to Y" or "Line X".

## Depends on

- F119 and F121: `LineRangeEditor` in the header of `DraftBox`.

## Facts

Verified on 2026-11-09 in the code:

- `LineRangeEditor` puts the read text and the inputs in one grid cell, and the link comes after the cell. The cell is as wide as the inputs, so the read text has a gap after it.
- The inputs are as wide as the digits of the last line of the file, plus padding. A one-line comment reads "Line 9", and its inputs read "Lines [9] to [9]".
- In the header, only the menu button comes after the editor, and it has `margin-left: auto`. In the edit form, nothing comes after the editor.

## Decisions

- Jan chose "Link follows" in the session: no gap on any pending comment. When the inputs open, "Done" comes directly after them. This replaces the F119 decision that the link does not move. The header keeps its height.
- The inputs keep their width, so typing does not move "Done".
- No new decision number: D115 says nothing about the layout.

## In scope

- `assets/comments/LineRangeEditor.tsx`, `assets/comments/LineRangeEditor.module.css`.
- `tests/ui/CommentForm.spec.ts`.

## Out of scope

- The width and the look of the inputs.

## Acceptance tests

- UI: in read mode, the gap between the lines and "Update selection" is the gap of the editor, for a range and for one line.
- UI: "Update selection" and "Done" keep the height of the header, and "Done" comes directly after the inputs.

## Verification

- `make front` and `make ui` in the worktree. `check-main.sh` after the merge runs every gate.
