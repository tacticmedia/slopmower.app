# F62 Scroll to a line gives up early

Workflow, conventions, and status: [feature track](features.md). F61 found a user-facing bug (candidate "Scroll to a line gives up early"): a link to a line of a large file, for example `#L3000`, can leave the page at the top of the file.

## Goal

- A link to a line scrolls to that line when the view can show it, also when the worker pool of the page needs a few seconds to start.
- The scroll still ends: at the line, at the first user input (F61), or after `MAXIMUM_SCROLL_WAIT_MS`.

## Facts

Verified in the code on 2026-10-16:

- `CodeFile` scrolls to the line of the hash in a `requestAnimationFrame` loop (`scrollWhenRendered`). The loop ends with `done`, after `MAXIMUM_SCROLL_MOVES` (60) moves, after `MAXIMUM_SCROLL_WAIT_MS` (10 s), or at the first user input (F61).
- While the worker pool is not ready, the view has no rows, and each step clamps the scroll and counts it as a move. So 60 frames, about 1 s, end the loop before the rows exist (F61 report: with the worker held for 3 s, the page stayed at the top).
- `tests/ui/LargeFile.spec.ts` holds the worker script with `page.context().route(...)` (F61), so a test can delay the rows.

## Decisions

- **Count only real moves.** A step counts toward `MAXIMUM_SCROLL_MOVES` only when the view knows the lines of the file and the scroll target changed. While the view has no rows, only `MAXIMUM_SCROLL_WAIT_MS` limits the wait. The implementer reads the step results (`moved`, `done`) and picks the smallest correct change.
- `MAXIMUM_SCROLL_MOVES` and `MAXIMUM_SCROLL_WAIT_MS` keep their values, unless a measurement shows that 60 real moves are too few for a large file.

## Depends on

F61 (the user input stop, and the worker hold in the UI test).

## In scope

- `assets/code/CodeFile.tsx` and a UI test in `tests/ui/LargeFile.spec.ts`.
- The candidate in [features.md](features.md), which F62 closes.

## Out of scope

- The start time of the worker pool (candidate "Worker pool of the UI build").

## Acceptance tests

- **UI**: with the worker held for 3 s, `#L3000` shows line 3000 below the file header after the release.
- **UI**: the existing scroll tests and the F61 user input test pass.

## Verification

- `make check` is green.
