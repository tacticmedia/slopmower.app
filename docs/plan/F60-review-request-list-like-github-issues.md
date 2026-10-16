# F60 Review request list like GitHub Issues

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-16: the Review Detail page is too busy, and the table of requests does not fit the width, because the request text pushes everything to the right. Make the request list look closer to GitHub Issues.

## Goal

- The request list fits the window at every width from 390 px. A long title wraps.
- A row reads like a GitHub issue row: state icon, title, a few labels, one muted meta line.
- "Open" and "Closed" split the list as on GitHub, and the filters take one row.
- The review header shows the title and the two main actions. The rest is one click away.

## Facts

Verified on 2026-10-16:

- `assets/routes/requests/RequestList.tsx` (184 lines) renders a Primer `DataTable` in `Table.Container` with the columns Request (`summary`), Status, Turn, Location, Claimed by, and Updated. Primer turns a column without `width` into `minmax(max-content, 1fr)` (`node_modules/@primer/react/dist/DataTable/useTable.js`), so the Request, Location, and Claimed by columns are at least as wide as their longest line, and a summary of 200 characters never wraps.
- Above the table (`data-testid="request-filters"`): a `SegmentedControl` of the statuses, the checkboxes "Waiting for you" (`awaiting=user`), "Outdated", and "Suggestions" (`suggestion=pending`), the path prefix input with "Filter", and "New request". Below the table title: the "Pending drafts" section.
- `requestListLoader` (`requestData.ts`) reads the URL filters and asks Q1 with `sort=updated`. Q1 takes `status` and `awaiting` as comma-separated lists, `path`, `outdated`, `suggestion`, `sort` (`created`, `updated`, `path`), and `limit`.
- `RequestSummary` has `status`, `awaiting`, `closed`, `anchor`, `summary`, `author`, `claimedBy`, `claimedAt`, `commentCount`, `commitCount`, `outdated`, `createdAt`, `updatedAt`, `requireApproval`, `suggestion`, `proposal`. `closed` is true exactly when `awaiting` is `none`.
- `Review.counts` has `total`, `byStatus`, `byAwaiting`, and `outdated`. The UI uses only `total` (the tab counter).
- `assets/routes/reviews/ReviewLayout.tsx` (93 lines) shows in its header: the repository link, the h1, the meta line; the buttons Quick open, Finish your review, Carry over requests, Close review, Delete review; the block with the review ID, "Agent prompt", "Review prompt", their copy buttons, and "Connect your agent"; `IndexProgress` in every state; then `UnderlineNav` Code | Requests and the closed banner.
- Tests that read these parts: UI `GeneralRequest`, `PendingDrafts`, `AskForPlan`, `OutdatedLabel`, `Reopen` (clicks the first checkbox of `request-filters`), `Suggestion`, `ReviewLifecycle`, `DeleteReviewFlow`, `CarryOverOpenReview` (find buttons in `header`), `IndexProgress`, `BatchReview`; the helper `REQUEST_ROWS = '[data-testid="request-list"] tbody tr'`; E2E `VerticalSliceTest` and `ProposalFlowTest` (list links), and the F57 journey `ReviewRequests.spec.ts`.

## Decisions

- **List:** a bordered list (`<ul data-testid="request-list">`, one `<li data-testid="request-row">` per request) replaces the `DataTable`. `REQUEST_ROWS` changes to the rows.
- **Row** (CSS grid: icon, body, aside):
  - A status Octicon with the colour of the `StatusLabel` variant, and the status text as its accessible name and tooltip (`data-testid="status-label"`, `data-status`). Icons: open `IssueOpenedIcon`, in progress `IssueTrackedByIcon` or the nearest Octicon that exists in `@primer/octicons-react` 19.38.0, ready for review `IssueClosedIcon` with the success colour, verified `CheckCircleIcon` with the done colour, reopened `IssueReopenedIcon`, won't do `SkipIcon`.
  - The title link (`summary`), semibold, wrapping (`min-width: 0`, `overflow-wrap: anywhere`), followed inline by the small labels: "Waiting for you" only when `awaiting === 'user'`, "Plan to approve", "Suggestion", "Outdated".
  - The meta line, muted and small: `AnchorLink` or "General", "opened {relative time} by {author}", and "claimed by {agent}" while `in_progress`.
  - The aside: the comment count with `CommentIcon` and the commit count with `GitCommitIcon`, each only when not zero, and "updated {relative time}". Below 544 px the aside moves under the meta line.
- **List header** (one row, wraps on a narrow window):
  - Left: "Open {n}" and "Closed {n}" as toggle links. Open is `awaiting=user,agent`, Closed is `awaiting=none`. The counts come from `review.counts.byAwaiting` (`user + agent`, and `none`) and are the review totals, not the filtered ones. Open is the default, as on GitHub.
  - "Waiting for you": a toggle on the Open tab, `awaiting=user`. It is not shown on the Closed tab.
  - Right: `ActionMenu` "Status" (single select of the statuses, "All" first), `ActionMenu` "Filters" (Outdated, Suggestions, as checkable items), `ActionMenu` "Sort" (Recently updated `updated`, the default; Oldest `created`; Path `path`), the path prefix input, and "New request".
  - Every filter stays a URL search parameter with its Q1 name, so a reload and the live revalidation keep it.
  - The empty state: "No request matches these filters." with a link that clears them.
- **Pending drafts:** unchanged, above the list.
- **Review header** (`ReviewLayout.tsx`):
  - Keep the repository link, the h1, the meta line, "Quick open", and "Finish your review" (primary, with the draft count).
  - "Carry over requests", "Close review", and "Delete review" (danger, last, after a divider) move into a kebab `ActionMenu` with the accessible name "Review actions". The dialogs do not change.
  - The review ID, the agent prompt, the review prompt, and "Connect your agent" move into one "Agent prompts" button that opens an `AnchoredOverlay` with the existing `ReviewIdCopy` and `AgentPromptSnippet`.
  - `IndexProgress` renders only while the index runs or after it failed. A ready index shows nothing.

## Depends on

F59 (labels), F58 (titles), F57 (journeys).

## In scope

- `assets/routes/requests/RequestList.tsx`, `RequestList.module.css`, `requestData.ts` (the Open and Closed filter, the sort parameter), new row and header components under `assets/requests/` where `RequestList.tsx` would pass about 200 lines.
- `assets/routes/reviews/ReviewLayout.tsx` and its module, `assets/review/IndexProgress.tsx`.
- Tests: the specs, helpers, and E2E selectors listed in Facts that this layout breaks, and the F57 journeys `ReviewRequests.spec.ts` and `Dialogs.spec.ts`.
- Docs: `docs/user/reviewing.md` (the request list, Open and Closed, the review actions menu, the agent prompts), the `architecture.md` "Requests" and review screen rows.

## Out of scope

- Request numbers per review (GitHub `#N`). Candidate.
- Filtered counts in the Open and Closed toggles. They would need a count per filter from Q1.
- The Code tab.

## Acceptance tests

- **UI** `RequestListLayout.spec.ts`:
  - A request with a title of 200 characters wraps, and the list has no horizontal overflow (`scrollWidth <= clientWidth` of the list and the document) at 390 px and at 1280 px.
  - The page opens on Open with `awaiting=user,agent` in the Q1 call, and the toggles show the counts of `byAwaiting`. Closed sends `awaiting=none`.
  - "Waiting for you" sends `awaiting=user`. The Status, Filters, and Sort menus send `status`, `outdated` or `suggestion`, and `sort`.
  - A row shows the labels of its rules: "Waiting for you" only for `awaiting: user`, "Plan to approve" only for a pending proposal.
- **UI** `ReviewLifecycle`, `DeleteReviewFlow`, `CarryOverOpenReview`: the actions open from "Review actions". `IndexProgress`: a ready index shows nothing, a running one shows the progress bar.
- **UI**: "Agent prompts" opens the overlay, and its copy buttons copy the prompts.

## Verification

- `make check` is green.
- `make journeys`: the requests tab and the review header at 390 px and at 1280 px.
