# F118 Flaky tests

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-05: "Fix all of the flaky tests." It takes the known issue "Flaky CodeView Ctrl or Meta click test" and the candidate "ProposalInFileView under load" of the Candidates list, plus each flaky test that the repeated runs of this feature find.

## Goal

- Each gate of `make check` gives the same result in repeated runs on one commit.
- Each flaky test gets its cause fixed, in the code or in the test. No retry and no longer wait hides a race (F55).

## Facts

Verified on 2026-11-05 on `main` at `7f4797b4`:

- `playwright.config.ts`: `workers: 4`, `retries: 0`, `expect.timeout` 10 s (F55).
- Known flaky tests:
  - `tests/ui/CodeView.spec.ts` "A file in the tree is a link: a Ctrl or Meta click opens a new page, and a click opens it in place": `waitForEvent('page')` times out, 2 of 100 runs on the F117 branch, 1 of 100 on `a3c4a81e` (F117 report).
  - `tests/ui/ProposalInFileView.spec.ts`: one failure at a load of 12 to 57 in F62, 20 of 20 passes afterwards (F62 report).
- The UI suite with `--repeat-each=10` (2570 tests, 6.0 min, load about 8): 1 failure, `RangeComment` "A drag from 5 to 9, a drag from 9 to 5, and click with shift-click all give the anchor 5 to 9". `fill()` of the third comment box left it empty, so "Add comment" stayed disabled.
- Cause of the `RangeComment` failure, measured with logs in the page and in `@pierre/diffs` 1.5.2:
  - The new comment box makes the page taller (1439 to 1781 px), and its first focus scrolls the window (156 to 1061 px) in the same frame.
  - The virtualizer handles that scroll before its resize notification and before it measures the file again. It clamps the scroll position to the old page height (719 px) and computes the window with the old file height (629.5 px instead of 1362.5 px), so it renders no row of the file.
  - That render replaces the rows with `innerHTML`, which removes the slot of the comment box. The textarea loses the focus to `body` for about 30 ms, until the focus retry of `CommentBox` takes it back. A `fill()` in that time types into `body`.
  - It happens in each run of this flow; the test fails only when `fill()` falls into the 30 ms. `@pierre/diffs` 1.6.4, the latest version, has the same clamp.

## Decisions

- **Code view:** on each window scroll that sees a new page height, `FileView` of `CodeFile.tsx` calls `reconcileHeights()` of the file instance and `markDOMDirty()` of the virtualizer before the virtualizer renders. Both are public API of `@pierre/diffs`.
- **CodeView Ctrl or Meta click:** the test checks the part of the page, not the tab of the browser. 16 of 1200 runs with logs in the page failed. In each failure the click was trusted, on the link, with `metaKey` true, with user activation, not default-prevented, and without a navigation in place, the same as in the passes; no tab opened within 8 s. So headless Chromium drops the tab, and opening it is the browser's job (no test of framework behaviour). The test now asserts that the Ctrl or Meta click is not default-prevented and the page stays, and that a plain click is default-prevented and opens the file in place.
- **ProposalInFileView:** not reproduced: 600 of 600 runs passed at a load of 85 to 96, 300 with the code view change and 300 without it. No change; the candidate is closed with this evidence.
- **Reproduce before a fix:** each flaky test is reproduced with `--repeat-each`, under load when it fails only under load, and its cause is found with a trace or with logs.
- **No retries** and no longer `expect.timeout`.

## In scope

- `assets/code/CodeFile.tsx`.
- The tests that the repeated runs find flaky, and the code under them.
- `docs/developer-guide.md`, `docs/plan/README.md` (Known gotchas), when a finding changes a rule there.

## Out of scope

- A patch of `@pierre/diffs`. The clamp is reported upstream by Jan, if he wants to.
- Failures that come from the host only, for example a browser launch timeout at a load above 100 (F55).

## Acceptance tests

- New UI test `CommentBoxFocus` "A comment box that opens below two new threads keeps the focus while the window scrolls to it": it counts the `focusout` events of textareas. It fails in 8 of 8 runs without the code change and passes in 8 of 8 with it.
- `CodeView` "A file in the tree is a link: a Ctrl or Meta click is left to the browser, and a click opens it in place" passes in 300 of 300 runs, and fails when the tree item prevents a Ctrl or Meta click (mutation).
- Each fixed flaky test passes in at least 200 repeated runs, under load when it failed under load.
- The UI suite with `--repeat-each=10`, the PHP suites 3 times, and the e2e suite 3 times show no failure.

## Verification

- `make check` green.
