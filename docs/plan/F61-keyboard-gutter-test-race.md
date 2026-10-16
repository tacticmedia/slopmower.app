# F61 Keyboard gutter test race

Workflow, conventions, and status: [feature track](features.md). After F55, the UI test `KeyboardCommentOnLines` "The gutter + outside the visible code keeps the focus that Tab gives it, and Space opens the comment box" failed `check-main` after F56 (session request-ui-github-style-qa-review, 2026-10-16). Alone on main it failed 1 of 10 times with `--repeat-each=10` at a load average of 25.

## Goal

- The test passes every time, and a race in the code, if the test shows one, is fixed.

## Facts

Verified in the code on 2026-10-16:

- The test opens `LARGE_FILE` at `#L100`, waits until the "+" of line 100 exists (`openWithPlusOnLine100`), scrolls the window once by `innerHeight / 2 + 60`, and polls until the "+" is under the sticky file header or below the window. The poll timed out after 10 s.
- `CodeFile` scrolls to the line of the hash in a `requestAnimationFrame` loop. The loop stops when the line is placed, after `MAXIMUM_SCROLL_MOVES` (60) moves, or after `MAXIMUM_SCROLL_WAIT_MS` (10 s).
- The "+" can exist before the loop ends. A window scroll of the test during the loop is then undone by the next move of the loop.

## Decisions

- **Confirm the cause first.** Reproduce the failure, for example with `--repeat-each` and the load of F55, and show whether the loop moves the window back after the test scroll.
- **If the loop undoes a scroll, that is a bug for users too.** A user who scrolls while a link to a line loads gets pulled back. The loop stops when the user scrolls: a `wheel`, `touchmove`, or scroll key event, or a window scroll that the loop did not make. The test then waits for the end of the loop, or scrolls by a user event, whichever the fix makes correct.
- **If the cause is different,** fix that cause, and record it in the report.
- No retries and no longer timeout.

## Depends on

F55.

## In scope

- `tests/ui/KeyboardCommentOnLines.spec.ts`, and `assets/code/CodeFile.tsx` if the race is in the code, with a test of the new rule.
- Docs: `docs/plan/README.md` "Known gotchas" if a rule for specs comes out of it.

## Out of scope

- The 10 s limit of the scroll to a line (candidate "Scroll to a line after 10 s").

## Acceptance tests

- **UI**: the test passes 50 of 50 times with `--repeat-each=50` at a load average of about 25.
- **UI**, if the loop changes: a scroll of the user during the scroll to a line is kept.

## Verification

- `make check` is green, with `make act` if an `ACT_INPUTS` file changes.
