# F72 Vertical slice flake after the onboarding connect

Workflow, conventions, and status: [feature track](features.md). Found on 2026-10-20 at the merge of F71. Since F69, the full-stack `VerticalSliceTest` fails in some runs. The failures suggest a user-facing bug: after "Start review", the review renders while the URL stays on the repository page.

## Goal

- Find the cause of the failures, and fix it in the app if the app has the bug.
- `VerticalSliceTest` passes 30 of 30 runs while `make ui` loads the host.

## Facts

Verified on 2026-10-20 in the F71 stack (port 8271):

- **The changed flow:** since F69 (`cce5734`), `connectAndStartReview()` (`tests/E2E/VerticalSliceTest.php:82`) clicks "Connect" in the onboarding step and then waits with `waitForURL('/repositories/*')`. It then clicks "Start review" on the repository page and waits with `waitForURL('/reviews/*')` (line 90). Before F69, the test waited for the sync state "Ready" on the list and clicked the repository link.
- **Failure rate:** the test failed in 2 of 15 runs, 1 of 6 runs, and 4 of 8 runs while another agent was active on the host. After that, 28 runs in a row passed at a load average of about 5.
- **One failing run:**
  - `waitForURL('/reviews/*')` timed out after 30 s ("JSON-RPC request 17 timed out"), and `page->url()` still gave `/repositories/<id>`.
  - The access log of the `e2e` site shows that `POST /api/v1/repositories/<id>/reviews` answered. Within 0.1 s, the SPA then requested `/api/v1/reviews/<reviewId>`, `/drafts`, `/viewed-files`, and `/tree`.
  - The failure screenshot shows the review page.
- **The test without `waitForURL`:** the test was changed to wait for `[data-testid="file-tree"]` and to read the ID from `location.pathname`. Then 2 of 12 runs under extra load (`npx playwright test` running at the same time) failed in `openFile()` with the 404 page "The resource does not exist". So the ID taken from `location.pathname` did not name the review.
- **React Router:** 8.4.0 drops the redirect of a fetcher action when a navigation started after the submit (`node_modules/react-router/dist/development/lib/router/router.js`, `pendingNavigationLoadId > originatingLoadId`). `useLiveRevalidation` (`assets/live/useLiveRevalidation.ts`) revalidates only when `routerIdle()` sees no navigation and no busy fetcher.
- **Live updates ruled out:** a throwaway UI spec delayed V1 and pushed a `repository` live update during the delay. The redirect went to `/reviews/<id>`, and no revalidation ran during V1.
- **Other `waitForURL` calls:** in `SignInTest` and `DeleteAccountFlowTest` they wait for `/login`, which is a full document load.

## Decisions

- Find the cause first. Log `location.href`, `history.length`, and the router state (`navigation`, `fetchers`, `location`) around the click, then decide.
- Do not raise a timeout and do not add retries (plan README, journeys and UI rules).

## Depends on

F69.

## In scope

- `tests/E2E/VerticalSliceTest.php`. If the cause is in the app: the repository page, `StartReviewForm`, `LatestCommits`, the onboarding connect, and their tests.

## Out of scope

- Other E2E tests, unless they share the cause.

## Acceptance tests

- **E2E:** `VerticalSliceTest` passes 30 of 30 runs while the UI suite runs in parallel on the host.
- **UI:** if the cause is in the app, one spec that reproduces it against the mocked API.

## Verification

- `make check` is green.
