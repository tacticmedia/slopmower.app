# M25 Drafts, batch review, viewed files

Workflow, conventions, and status: [README](README.md).

## Goal

A GitHub-style pending review, and per-user "Viewed" files, decision D15. The contract is docs/specs/rest-api-requests.md section 5, and V11 and V12 of docs/specs/rest-api.md.

## Depends on

M21. M24: the batch arrives in other windows as one live update.

## In scope

- `Draft` entity: `user`, `review`, `kind` with `new_request` and `reply`, `anchor` captured server-side at creation as in Q2, `request` for replies, `body`, `createdAt`, `updatedAt`. Drafts are private to their user and are not events.
- `ViewedFile` entity: `user`, `review`, `path`. Unique per user, review, and path.
- A migration.
- Endpoints, all session only:
  - D1 `GET /api/v1/reviews/{reviewId}/drafts`: sorted by `createdAt`. It also works on a closed review;
  - D2 `POST /api/v1/reviews/{reviewId}/drafts`: `{kind: "new_request", anchor, body}` or `{kind: "reply", requestId, body}`. 201 with `Location: /api/v1/drafts/{id}`. 404 for an unknown request. 422 for anchor errors, a request of another review, or more than 500 drafts per user and review. 409 `review_closed`;
  - D3 `PATCH /api/v1/drafts/{draftId}`: `{body}` only. The anchor cannot change. 409 `review_closed`;
  - D4 `DELETE /api/v1/drafts/{draftId}`: 204. It also works on a closed review;
  - D5 `POST /api/v1/reviews/{reviewId}/drafts/submit`: `{summary}`, optional. One transaction takes all the caller's drafts of the review sorted by `createdAt`, creates one `batchId`, writes `request.opened` or `request.commented` with that `batchId` per draft, then `batch.submitted` with `summary`, `requestIds`, and `commentIds`, and deletes the drafts. 201 with `BatchSubmission`. 422 when there are no drafts. 409 `review_closed`;
  - V11 `GET /api/v1/reviews/{reviewId}/viewed-files`: `{reviewId, paths}`. The marks never reset inside a review;
  - V12 `PUT /api/v1/reviews/{reviewId}/viewed-files`: `{path, viewed}`, idempotent per path. 422 when the path is not a file at the review commit. 409 `review_closed`.
- Draft deletion uses the `inTransaction` callback of `CommandExecutor`, so it commits with the events. D5 deletes exactly the drafts that it read, so a draft created during the submission stays. V4 deletes all drafts of the caller in the review. Drafts are not event data, so the projection rebuild never touches them.
- UI:
  - "Start a review" next to "Add single comment" creates a draft;
  - a "Pending" label on draft threads, with edit through D3 and delete through D4;
  - the "Finish your review (n)" overlay with a summary field and submit;
  - a "Viewed" checkbox in the file header, and viewed marks in the tree;
  - the close dialog warns with the draft count when the review has drafts.

## Out of scope

- Suggested changes: [docs/backlog/suggested-changes.md](../backlog/suggested-changes.md).

## Files (representative)

- `src/Entity/Draft.php`, `src/Entity/ViewedFile.php`, `migrations/Version*.php`.
- `src/Application/DraftService.php`, `SubmitBatch.php`, `ViewedFiles.php`.
- `src/Controller/Api/DraftController.php`, `src/Controller/Api/ViewedFileController.php`, normalizers.
- `assets/review/FinishReviewOverlay.tsx`, `assets/review/ViewedCheckbox.tsx`, `assets/comments/*` changes.
- `tests/Functional/Api/Draft*Test.php`, `tests/Functional/Api/ViewedFilesTest.php`, `tests/E2E/BatchReviewTest.php`, `tests/E2E/ViewedTest.php`.

## Tasks (ordered)

1. Write the entities and run `make migration`.
2. Implement the draft service, the batch submission, the draft deletion on close, and the viewed files.
3. Write the controllers and add the cross-tenant cases.
4. Build the UI.

## Acceptance tests

- **F `SubmitBatchTest`** proves D5.
  - "Submission writes one batchId on contiguous seqs and deletes the drafts"
  - "A draft created during a submission stays"
  - "Submitting zero drafts gives 422 invalid_input"
  - "Submitting on a closed review gives 409 review_closed"
- **F `DraftRulesTest`** proves D1 to D4.
  - "The anchor is captured at creation"
  - "A 501st draft gives 422"
  - "A reply to a request of another review gives 422"
  - "PATCH changes the body only"
  - "List and delete work on a closed review, and create and update give review_closed"
- **F `DraftVisibilityTest`** proves that drafts stay private until submit.
  - "MCP sees nothing before submit and everything after"
- **F `CloseDeletesDraftsTest`** proves the close rule.
  - "Closing a review deletes the caller's drafts of that review"
  - "A projection rebuild keeps the current drafts"
- **F `ViewedFilesTest`** proves V11 and V12.
  - "Marks and unmarks a file, idempotently"
  - "A directory path gives 422"
  - "Marks belong to one user"
  - "A closed review gives 409 review_closed"
- The cross-tenant inventory covers every new route.
- **E `BatchReviewTest`** proves the pending review in the browser.
  - "Two drafts submitted with a summary appear as two threads without the Pending label"
  - "A draft can be edited and deleted before the submission"
- **E `ViewedTest`** proves the Viewed checkbox.
  - "A file marked as viewed shows the mark in the tree after a reload"

## Verification commands

```sh
make migration
make db-test
make check
```

## Done

A pending review works from the first draft to the submitted batch, and viewed marks persist per user.
