# F23 Focus mode shortcut

Workflow, conventions, and status: [feature track](features.md). From the F02 report.

## Goal

The reviewer turns focus mode on and off from the keyboard while a file is open.

## Decisions

Jan, 2026-10-07: pick the Candidates that help most when reviewing this repository with Slopmower, and decide what a sensible default decides.

- **The keys:** Cmd+Shift+Enter on macOS, Ctrl+Shift+Enter elsewhere (`Mod+Shift+Enter`). Firefox on macOS binds Cmd+Shift+F to full screen, and Safari binds Cmd+. to stop loading. Chrome binds Ctrl+Shift+Enter only in the address bar. A key without a modifier would need a way to turn it off (WCAG 2.1.4).
- **Where it acts:** only while a file is open, also on the error element of a file, where "Focus" stays. It does nothing in a text field, as the Quick open shortcut (`isEditableElement`). Escape keeps leaving focus mode.
- **Code:** the handler is in `ReviewLayout.tsx`, next to the Escape handler. The "Focus" buttons of `FileRoute.tsx` and `CodeLayout.tsx` show the keys with `KeybindingHint` and `aria-keyshortcuts`, as `QuickOpen.tsx` does. The modifier check of `QuickOpen.tsx` and the new shortcut share one helper, so there is one way to read `Mod`.
- **No contract change.**

## Depends on

F02, F07, F11 (merged). F21 (one scroll area, other session) changes the layout CSS of `CodeLayout` and `FileRoute` next to this change. Whichever merges second rebases. F23 keeps to the shortcut, the helper, and the buttons, not the layout CSS.

## In scope

- `assets/routes/reviews/ReviewLayout.tsx`, `FileRoute.tsx`, `CodeLayout.tsx`, `assets/review/QuickOpen.tsx`, one shared helper for the modifier check.
- Docs: `docs/user/reviewing.md` section "Focus mode", `docs/architecture.md` section 8 Review row, the developer guide counts.

## Out of scope

- Other shortcuts, a shortcut help dialog, or remapping.
- Focus mode on the Requests tab and the Changes tab.

## Acceptance tests

- **UI** `FocusMode.spec.ts`: with a file open, the shortcut turns focus mode on and off; in a comment box it does nothing; on the review page without an open file it does nothing.

## Verification

- `make check` is green.
- Manual check on the dev app: open a file, press Cmd+Shift+Enter twice.
