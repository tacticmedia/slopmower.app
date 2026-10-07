# F24 Gutter "+" keeps the focus

Workflow, conventions, and status: [feature track](features.md). A bug that the F21 implementer found; it exists on `main` too. Bugs before features: it goes ahead of the next feature.

## Goal

A keyboard user who tabs to the gutter "+" of a line opens the comment box with Enter or Space, also when that line was outside the visible part of the code view.

## Facts

- When the gutter "+" gets the focus outside the visible part of the code view, the browser scrolls it into view, `@pierre/diffs` renders its row again, and the focused button leaves the DOM. The focus goes to `body`, so Enter and Space do nothing.
- Tab reaches such a "+" after a focusable element in the view, for example a thread, that scrolled the view away from it. A shorter code view makes it more frequent.
- `keyboardGutterUtility()` in `assets/code/CodeFile.tsx` names the "+" on `focusin` and handles Enter and Space; it finds the line through `closest('[data-column-number]')`.

## Decisions

- **Fix in `keyboardGutterUtility()`.** When the focused "+" leaves the DOM (its `focusout` has no related target and the button is no longer connected), focus the "+" of the same line in the newly rendered row, after the view renders it. The new "+" gets the same name, so a screen reader announces the same control.
- The fix stays inside `CodeFile.tsx`, one of the two wrappers of `@pierre/diffs`. No library change.
- Check the render timing against the installed `@pierre/diffs` (1.5.2 since F18) before choosing between a microtask, an animation frame, or a `MutationObserver` on the view root.

## Depends on

None. F21 (file view fills the window, not merged yet) made the bug more frequent and set `KeyboardCommentOnLines.spec.ts` to a 1280x1000 viewport as a workaround; if F21 merges first, F24 removes that workaround.

## In scope

- `assets/code/CodeFile.tsx` (`keyboardGutterUtility()`).
- A UI spec case that reproduces the bug: the "+" of a line gets the focus while the line is outside the visible part of the code view, and Space opens the comment box for that line. Prefer a small viewport or a scrolled view over timing tricks, so the case fails without the fix every time.

## Out of scope

- Other focus behaviour of the code view.
- A change of the review header height (a separate idea from the F21 report).

## Acceptance tests

- **UI** in `KeyboardCommentOnLines.spec.ts`: the case above fails on the code before the fix and passes after it, in 20 of 20 runs.
- The existing keyboard specs stay green.

## Verification

- `make check` is green.
- Manual check: in the dev app, tab from a thread to the "+" of a line far below it and press Space; the comment box opens.
