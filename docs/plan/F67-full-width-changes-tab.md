# F67 Full-width Changes tab

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-19: use the full window width whenever a file is open for review, and keep the centered width for lists. The code browser is already full width since F25. Jan confirmed that he means the request detail page while it shows diffs.

## Goal

The Changes tab of a request uses the full window width, like "Files changed" of a GitHub pull request. The Conversation tab, the lists, the repository pages, and Settings stay centered at 1280 px.

## Facts

Verified on 2026-10-19:

- `assets/layout/RootLayout.module.css:27-37`:
  - `.main` has `max-width: 1280px`.
  - `.page:has([data-code-browser]) .main` sets `max-width: none` and `padding-bottom: 0`.
- `assets/routes/reviews/ReviewLayout.tsx:56,60` sets `data-code-browser` on every review tab but Requests. No test names the attribute.
- The code browser needs `padding-bottom: 0` to reach the bottom edge (`CodeLayout.module.css`). The request page does not.
- `assets/routes/requests/RequestDetail.tsx`:
  - the Changes tab is `?tab=changes` (`CHANGES_TAB`, `assets/requests/requestTabs.ts`);
  - it renders `RequestChanges` with `CommitDiff`.
- `RequestDetail.module.css:41-77` has no max-width. At 1012 px and wider, `.layout` is `minmax(0, 1fr) 256px` with the areas `'tabs tabs' 'main sidebar'`, on both tabs.
- Layout tests:
  - `tests/ui/CodeBrowserLayout.spec.ts:43-68`: the code view reaches the right edge;
  - `tests/ui/RequestDetailLayout.spec.ts:98-112`: at 390 px the sidebar is between the header and the tabs.

## Decisions

- `RootLayout.module.css` gets two rules:
  - `.page:has([data-full-width]) .main { max-width: none }`;
  - `.page:has([data-code-browser]) .main { padding-bottom: 0 }`.
- `ReviewLayout` sets both attributes on the Code tab. `RequestDetail` sets `data-full-width` while the Changes tab shows.
- From 1012 px, the Changes tab has one column (`'tabs' 'main'`), and the sidebar is hidden, as on the "Files changed" tab of GitHub. Below 1012 px, the layout does not change.

## Depends on

F64, which changes the request sidebar.

## In scope

- `RootLayout.module.css`, `ReviewLayout.tsx`, `RequestDetail.tsx`, `RequestDetail.module.css`.
- UI `ChangesTab.spec.ts` (or `RequestDetailLayout.spec.ts`).

## Out of scope

- The proposal diffs in the Conversation tab. They stay in the centered thread.
- A sidebar toggle on the Changes tab.

## Acceptance tests

- **UI** at 1600 px:
  - on the Changes tab, the commit section is wider than 1280 px, and the sidebar is hidden;
  - on the Conversation tab, `main` is at most 1280 px wide, and the sidebar shows.
- **UI:** `CodeBrowserLayout.spec.ts` and `RequestDetailLayout.spec.ts` stay green.

## Verification

- `make check` is green.
- `make journeys`: the Changes tab at 1280 px and wider.
