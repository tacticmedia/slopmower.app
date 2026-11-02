# F96 Send one draft now

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "I want to be able to convert an individual comment from a draft to a submitted comment - this will be for simple questions that I want to ask about."

## Goal

A pending draft has the action "Send now". It opens its request at once, and the other drafts stay pending.

## Facts

Verified on 2026-11-03:

- D5 `POST /api/v1/reviews/{reviewId}/drafts/submit` (`DraftController::submit`, `:167-233`) reads every draft of the caller with `findOfUser` (`:191`), decides each one, appends `batch.submitted`, and deletes exactly the drafts that it read (`:203-207`). `rest-api-requests.md` (D5) says that it takes all drafts of the caller.
- `SubmitDraftsInput` has `summary` and `delivery`. Without `delivery`, the review keeps its value (D85).
- The `DraftBox` menu has "Edit" and "Delete" (`DraftBox.tsx:80-94`). Delete hides the draft at once and brings it back when D4 fails.
- The intents go to the API through `commentAction.ts` and `commentIntent.ts`. `tests/ui/support/fakeReview.ts` simulates D5 in `#submitDrafts`.

## Decisions

- D100: D5 takes `draftIds`, 1 to 500 unique draft IDs. With it, D5 submits only those drafts of the caller in this review, and the other drafts stay. An ID that is not such a draft gives 422 `invalid_input` on `draftIds`, and nothing changes. The events are those of a normal submit, a batch of one or more.
- "Send now" sends D5 with `draftIds` of that one draft, no summary, and no delivery.

## Depends on

F95, which changes `CommentBox`. This feature changes `DraftBox` and the draft inputs.

## In scope

- **Specs:** D5 in `rest-api-requests.md`, `SubmitDraftsInput` in `inputs.schema.json`, an input fixture, the amendment list of `docs/specs/README.md`, D100 in architecture section 11.
- **Back end:** `SubmitDraftsInput`, `DraftController::submit`.
- **SPA:** `DraftBox` ("Send now", hidden at once as Delete does), `commentIntent.ts`, `commentAction.ts`, `fakeReview.ts`, `assets/api/types`.
- **User docs:** `docs/user/comment.md`.

## Out of scope

- "Send now" in a new comment box without a draft.
- A selection of several drafts in the UI.

## Acceptance tests

- Functional: D5 with `draftIds` opens only those drafts, and the other drafts stay. An unknown ID, or a draft of another user, gives 422, and nothing changes.
- UI: "Send now" on one of 2 drafts sends D5 with `draftIds`, one draft stays, and the "Submit feedback" count goes from 2 to 1.

## Verification

- `make check` is green.
- Mutation check: without the filter, the functional test fails.
