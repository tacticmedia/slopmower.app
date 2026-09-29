# M18 Repositories and reviews screens

Workflow, conventions, and status: [README](README.md).

## Goal

Connect a local repository, start and close reviews, and hand the review ID to the agent.

## Depends on

M12, M17.

## In scope

- `/repositories`:
  - the candidates of R2 and the connected repositories of R1;
  - a connect action through R3;
  - the sync state and `lastError` of each repository;
  - a notice when local repositories are disabled, from `features.localRepositories` of `/api/v1/me`.
- `/repositories/:id`:
  - "Refresh" through R5;
  - the reviews list of V2, with "Show closed", which switches `state` to `all`;
  - a start form for V1. `ref` defaults to the default branch. `title` is optional.
- The `/reviews/:id` layout:
  - title, ref, and short SHA;
  - a copyable review ID and an agent prompt snippet, "Work on Holistic Review <reviewId>", decision D8;
  - "Close review" in the header: a Primer `Dialog` with an optional note, through V4, decision D27;
  - a read-only banner when the review is closed.
- Forms submit through route `action`s and `useFetcher`. `fetcher.state` shows the pending state. API errors show the `message` of the Error body.

## Out of scope

- The code view: M20.
- GitHub candidates: M33.
- The draft warning before close: M25.

## Files (representative)

- `assets/routes/repositories/*.tsx`, `assets/routes/reviews/ReviewLayout.tsx`.
- `assets/review/ReviewIdCopy.tsx`, `AgentPromptSnippet.tsx`, `CloseReviewDialog.tsx`, `assets/review/promptSnippet.ts`, CSS modules.
- `assets/review/promptSnippet.test.ts`.
- `tests/E2E/RepositoriesTest.php`, `tests/E2E/ReviewLifecycleTest.php`, `tests/E2E/ApiErrorDisplayTest.php`.

## Tasks (ordered)

1. Build the repositories screens.
2. Build the review layout with the copy controls and the close dialog.
3. Write the tests. The copy test checks the "Copied" label. Grant the clipboard permission in Chromium if the copy needs it.

## Acceptance tests

- **V `PromptSnippetTest`** proves the agent prompt.
  - "The snippet names the review ID"
- **E `RepositoriesTest`** proves the repositories screen.
  - "A repository that the test created is listed as a candidate"
  - "Connect adds it to the connected repositories"
  - "A notice shows when local repositories are disabled"
- **E `ReviewLifecycleTest`** proves the review flow in the browser.
  - "The review page shows the review ID and the prompt snippet"
  - "The copy button shows Copied"
  - "Two open reviews of one repository are listed"
  - "After close, the review is hidden, shows with Show closed, and is read-only"
- **E `ApiErrorDisplayTest`** proves error display.
  - "Starting a review at an unknown ref shows the API message"

## Verification commands

```sh
make front
make e2e
make check
```

## Done

A user reaches a review without `curl`.
