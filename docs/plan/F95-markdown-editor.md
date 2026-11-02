# F95 Markdown editor with Write and Preview

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "When writing the comment description, I want to use the same editing experience as what Github Issues have, including some basic markdown formatting, using tab/shift+tab, and probably more importantly, the Preview tab."

## Goal

Every comment box has the Write and Preview tabs, a Markdown toolbar, the GitHub formatting shortcuts, and Tab and Shift+Tab indenting of the selected lines.

## Facts

Verified on 2026-11-03:

- `assets/comments/CommentBox.tsx` is the only comment-body editor. It uses a Primer `Textarea` (`:127-137`). Its form handles Mod+Enter (submit) and Escape (cancel) (`:107-118`). `quote()` appends a quote and focuses the field (`:79-89`).
- Comment bodies render through `assets/comments/MarkdownBody.tsx` (react-markdown 10.1.0, remark-gfm 4.0.1), loaded lazily.
- Primer React 38.40.0 has no `MarkdownEditor`. Its CHANGELOG: 37.0.0 "Delete deprecated draft components `MarkdownEditor`, `MarkdownInput`, and `InlineAutocomplete`". `@primer/react/experimental` exports `UnderlinePanels`.
- `@github/markdown-toolbar-element` 2.2.3 is the latest release (npm, 2026-11-03). MIT, repository not archived. Primer's removed editor drove it through hidden `<md-*>` elements (`_FormattingTools.tsx` at `@primer/react@36.27.0`). Its key handlers serve the toolbar buttons only, not the textarea.
- Tab on GitHub: Primer's removed `_useIndenting.ts` indents the selected lines by 2 spaces on Tab and dedents them on Shift+Tab, only when the selection is not empty. Without a selection, Tab moves the focus.
- GitHub shortcuts (docs.github.com, keyboard shortcuts, comments): Mod+B bold, Mod+I italic, Mod+E code, Mod+K link, Mod+Shift+7 ordered list, Mod+Shift+8 unordered list, Mod+Shift+. quote, Mod+Shift+P toggles Write and Preview, Mod+Enter submits.
- Quick open uses Mod+K (`assets/review/QuickOpen.tsx:18`).
- The keyboard specs (D31) move from the text field to the buttons with Tab (`tests/ui/support/codeView.ts`, `tabToButton`). `COMPOSER` is `form[aria-label^="Comment on"] textarea`.

## Decisions

- Add `@github/markdown-toolbar-element` 2.2.3.
- A new `MarkdownEditor` component in `assets/comments/` replaces the `Textarea` of `CommentBox`. `CommentBox` keeps its props, its keys, and `quote()`.
- **Tabs:** Write and Preview. The textarea stays mounted while Preview shows, so its undo history and its ref stay. Preview renders `MarkdownBody`, or "Nothing to preview" for a blank body. `quote()` switches to Write.
- **Toolbar:** icon buttons Heading, Bold, Italic, Quote, Code, Link, Bulleted list, Numbered list, Task list. Each button clicks a hidden `<md-*>` element of `<markdown-toolbar for="...">`, so the edit keeps the undo history.
- **Keys** in the text field: the GitHub shortcuts above. Mod+K in the field inserts a link and does not reach quick open.
- **Tab:** Tab and Shift+Tab indent and dedent the selected lines by 2 spaces when the selection is not empty. Without a selection, Tab moves the focus. Our own small function, tested with Vitest.

## Depends on

Nothing. F93 changes `CommentBox` too; this feature runs after it.

## In scope

- `package.json` and `package-lock.json`.
- `MarkdownEditor.tsx` with a CSS module, a type declaration of the custom elements, `CommentBox.tsx`.
- `docs/user/comment.md` and `docs/user/keyboard-shortcuts.md`.

## Out of scope

- List continuation on Enter, a pasted URL over a selection as a link, file upload, mentions, saved replies.
- `NoteDialog`, `SubmitFeedbackOverlay`, and `CloseReviewDialog`.

## Acceptance tests

- Vitest: indent and dedent of a multi-line selection, a tab character or 1 or 2 spaces removed on dedent, a line without indentation unchanged on dedent.
- UI, `tests/ui/MarkdownEditor.spec.ts`:
  - Bold wraps the selection in `**`.
  - Tab with a selection over 2 lines indents both, and Shift+Tab dedents them.
  - Tab without a selection moves the focus to the next control.
  - Preview shows `<strong>` for `**x**`, and Write shows the text again.
  - Mod+Shift+P toggles the tabs.
  - Mod+K in the field inserts a link and opens no quick open.
- The existing keyboard and comment specs pass unchanged.

## Verification

- `make check` is green.
- The `CodeBrowser` journey capture of the comment box shows the tabs and the toolbar.
