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

- **Cause** (found on 2026-10-20 with a log of `pushState`, `replaceState`, `popstate`, `fetch`, and live updates around the clicks; the first instrumented run failed at a load average of 8):
  - 476 ms: the Connect redirect pushes `/repositories/<id>`. 477 ms: a live `repository` update revalidates. `router.revalidate()` on an idle router starts an uninterrupted revalidation, so `navigation.state` stays `idle`.
  - 551 ms: "Start review" posts V1. 766 ms: V1 answers, and the fetcher redirect starts the navigation to `/reviews/<id>`.
  - 825 ms: the stale revalidation completes and pushes `/repositories/<id>`. About 1056 ms: the review navigation completes without a history entry. The review renders, and the URL names the repository.
  - React Router 8.4.0 `startRedirectNavigation()` sets `pendingNavigationController = null` (`router.js:1044`) before `startNavigation()`, which aborts that controller (`router.js:503`). For a fetcher redirect, the controller belongs to the running revalidation or navigation, so nothing aborts it. Its `completeNavigation()` then runs with the redirect's `pendingAction` `PUSH` and resets it to `POP`. 8.5.0 (2026-10-19) has the same code (`router.js:1125`).
  - Every action that a fetcher submits and that redirects has the bug: Start review (`StartReviewForm`, `LatestCommits`), the onboarding Connect (`CandidateConnection` with `openRepository`), and Delete review (`DeleteReviewDialog`). A user navigation that is still loading has it too.

## Decisions

- Find the cause first. Log `location.href`, `history.length`, and the router state (`navigation`, `fetchers`, `location`) around the click, then decide.
- Do not raise a timeout and do not add retries (plan README, journeys and UI rules).
- **Fix in React Router (D82).** `bin/patch-react-router.mjs`, the `postinstall` of `npm ci`, patches `startRedirectNavigation()` in `dist/development` and `dist/production`: `if (isNavigation) pendingNavigationController = null;`. A fetcher redirect then aborts the running load, as every other navigation does. A navigation's own redirect keeps its request. The script fails for another React Router version, so an upgrade forces a check.
- **No patch-package.** 8.0.1 depends on `braces` 3.0.3 through `find-yarn-workspace-root` and `micromatch`, and GHSA-vfj7-8cjw-p6xm (high) has no fixed `braces`, so `npm audit` in CI fails.
- The fetchers stay. React Router documents fetcher redirects, and two of the four forms show an error per row.
- Jan reports the bug upstream (For Jan). The patch goes when a release fixes it.
- The temporary instrumentation of `VerticalSliceTest` is removed. The test was right.

## Depends on

F69.

## In scope

- `tests/E2E/VerticalSliceTest.php`. If the cause is in the app: the repository page, `StartReviewForm`, `LatestCommits`, the onboarding connect, and their tests.
- `bin/patch-react-router.mjs`, `package.json` (`postinstall`), the `assets` stage of `Dockerfile`, `ACT_INPUTS` of the `Makefile`.
- One Vitest test of the patched router.
- `docs/architecture.md` D82, `docs/developer-guide.md` (the patch), `features.md` For Jan (the upstream report).

## Out of scope

- Other E2E tests, unless they share the cause.

## Acceptance tests

- **E2E:** `VerticalSliceTest` passes 30 of 30 runs while the UI suite runs in parallel on the host.
- **UI:** if the cause is in the app, one spec that reproduces it against the mocked API.
- **Vitest** `assets/fetcherRedirect.test.ts` instead of a UI spec: a fetcher redirect during a held `revalidate()` ends at its target in `router.state.location` and in the history. It fails without the patch on every run. A UI spec against the mocked API could only fail by timing, because the stale load must complete between the redirect start and the review load.

## Verification

- `make check` is green.
