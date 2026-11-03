# F110 Editor tabs and toolbar on one line

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "on the text editor tabs, there are Write and Preview tabs that stick out on a separate line, and under it is the toolbar for bold, italic and so on. Move the tabs and the formatting buttons onto a same line."

## Goal

The Markdown editor of F95 has one header row: the "Write" and "Preview" tabs at the left, the formatting toolbar at the right, as in the GitHub comment box.

## Facts

Verified on 2026-11-03 on `main` at `f4a1726f`:

- `MarkdownEditor` uses `UnderlinePanels` of `@primer/react/experimental` 38.40.0. It renders only its tabs in the tab row and drops other children, so the toolbar sits in the "Write" panel below the tabs.
- `@primer/react/experimental` exports `Tabs`, `useTabList`, `useTab`, and `useTabPanel`, the hooks that `UnderlinePanels` is built on. `Tabs` renders no element.
- `tests/ui/MarkdownEditor.spec.ts` finds the tabs by the role `tab`.

## Decisions

- `MarkdownEditor` builds the tabs with `Tabs` and the three hooks: a header row with the tab list and, while "Write" is selected, the toolbar. The focus order is tabs, toolbar, text field.
- The tabs keep the underline look of `UnderlinePanels` through the CSS module, with Primer tokens.
- The `ActionBar` fills the rest of the row, so it moves the buttons that do not fit into its overflow menu.

## Depends on

Nothing.

## In scope

- `assets/comments/MarkdownEditor.tsx` and its CSS module.
- `tests/ui/MarkdownEditor.spec.ts`.

## Out of scope

- Other changes to the editor.

## Acceptance tests

UI:

- The "Write" tab and the "Bold" button have the same vertical position.
- The existing editor tests pass: tab switch, shortcuts, preview.

## Verification

- `make front` and `make ui` are green.
