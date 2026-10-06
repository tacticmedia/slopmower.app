# F07 Front-end bugs and UX fixes

Workflow, conventions, and status: [feature track](features.md). Hard rule: bugs and issues before features (`AGENTS.md`).

## Goal

Fix the open front-end bugs and the small UX defects found in the reports and in review sessions. Each item was checked against `main` at 26c6def. No contract change.

## Items

1. **Focus mode traps on an error page.** When the file route shows its error element in focus mode, the headers stay hidden and the Focus toggle is gone, so only Escape leaves focus mode. Focus mode must not apply to the error element, or the error element must offer the toggle.
   - Evidence: `assets/routes/reviews/ReviewLayout.tsx:64-66`, `FileRoute.tsx:97`, and `router.tsx:57`.
2. **The tree does not follow the open file.** When a file opens under a collapsed directory, the tree does not expand to it. This happens for links, back and forward, and F04's "Go to declaration". Expand the ancestors of the open file and keep the user's own expansions.
   - Evidence: `assets/review/FileTree.tsx:41` sets the state only at mount.
3. **A reply draft loses its label.** A pending reply draft reads "Reply to a request" when the list filters hide its request. It must name its request, for example from the draft's `requestId` and a cached summary, or from a summary read.
   - Evidence: `assets/routes/requests/RequestList.tsx:38`.
4. **A linked-commit link opens at the top.** It opens the Changes tab at its top, not at that commit. Scroll to `#commit-<sha>`, the id that each section already has.
   - Evidence: `assets/requests/CommitList.tsx:29` and `RequestChanges.tsx:36`.
5. **The Close review dialog closes mid-request.** It can be cancelled or closed while V4 runs. Block both until V4 answers.
   - Evidence: `assets/review/CloseReviewDialog.tsx:27,31`.
6. **The comment box:**
   - Escape cancels and Ctrl+Enter (Cmd+Enter on macOS) submits.
   - A failed Q2 reopens the box with its line selection; today `useFileComments.ts:83` clears the selection before the send.
   - Only one box can be open. Keep that rule.
7. **A symbol anchor shows only its lines.** A request anchored to a symbol shows only its lines. Show the symbol's FQN in `ThreadBox` and in the request detail.
8. **The anchor snapshot lacks context.** The request detail shows the snapshot without its `before` and `after` context lines (`assets/requests/snapshotRows.ts`). Show them, dimmed.
9. **The repository header hides GitHub details.** `RepositoryHeader` shows no GitHub details, although the API returns them. Show the owner, private or public, and a link to `htmlUrl` for a GitHub repository.
10. **The Changes tab loads everything at once.** It starts the reads for every file of every linked commit at once (`assets/code/CommitDiff.tsx:38,70`). Load a file's diff when its section is expanded or scrolled into view, and collapse long lists by default above a file-count threshold. Keep the anchored file first and expanded.
11. **"Symbols" is disabled without a reason.** When the file has no symbols, the button is disabled with no reason. Add a tooltip, "No symbols in this file", or "Symbols could not be loaded" when V9 fails. This is the candidate "Say why Symbols is disabled", moved here because it is a defect.
12. **No test for New request on a closed review.** Add a UI test that "New request" is hidden on a closed review.

13. **Double submit, added after review (2026-10-07).**
   - Primer `loading` only sets `aria-disabled`, so a second click or Enter submits a form again while its write runs.
   - Guard every write form in `assets/` as `FinishReviewOverlay` does (`onSubmit` that prevents the submit while the fetcher is not idle).
   - The known cases are Close review, Delete review, Start review (a duplicate review), Connect, and Refresh.
   - One UI test proves one write per double click.

## Out of scope

Contract changes. A batch thread read and the Q9 file order are listed under "For Jan" in `features.md`.

## Acceptance tests

- One Playwright UI test per item, against the mocked API, with the shortest assertion that proves the item. Items 6 and 10 may need two.
- The existing UI suite stays green, focus mode and line wrapping included.

## Verification

`make check`, and a manual pass of items 1, 2, 4, and 10 on the dev app.
