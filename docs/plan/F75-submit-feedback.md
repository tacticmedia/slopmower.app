# F75 Submit feedback: new comments only as drafts

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-10-20:
- "I want to remove the option to send a comment with no drafts. I want a set of comments to be only sent all at once."
- "Finish your review" is not the end of a review, because the user can add comments at new locations after it. It becomes "Submit feedback".

## Goal

- A new comment on code, and a general request, are always drafts. They reach the agent together with "Submit feedback".
- Replies in existing threads and the notes of actions still send at once. Jan decided this on 2026-10-20: a reply usually answers a question that the agent waits on.

## Facts

Verified on 2026-10-20:

- **New comments:**
  - A new comment in the code view (`FileRoute.tsx:296-315` with `CommentBox`, submit in `useFileComments.ts:88-111`) offers two buttons:
    - "Add single comment" (`FileRoute.tsx:302`): Q2, sent at once (`commentAction.ts:22-23`);
    - "Start a review", then "Add review comment" (`draftView.ts:44`): D2, a draft (`commentAction.ts:26-27`).
  - Ctrl/Cmd+Enter runs "Add single comment" (`CommentBox.tsx:114-116`). The draft button has the primary style (`CommentBox.tsx:182` against `:186`).
  - The general request box (`NewRequestBox.tsx:62-77`, rendered by `RequestListHeader.tsx:115`) has the same two buttons.
- **Q2 callers:** Q2 is session only (`docs/specs/rest-api.md:54`), so only the SPA calls it.
- **Replies:** Q4 `POST /requests/{id}/comments` in `ThreadBox.tsx:62-73` sends at once, in the code view and on the request page. The API has reply drafts (`CreateDraftInput.php:23-30`), but no screen creates one.
- **D15** (`architecture.md:811`) says: "Only new requests and replies are drafts. State changes happen at once." Drafts are private and are not events (`rest-api-requests.md:570`).
- **"Finish your review":**
  - `FinishReviewOverlay.tsx` shows in the review header while the user has drafts (`ReviewLayout.tsx:76`). It holds a count, Summary, "Submit review", and "Cancel".
  - Tests find it by `data-testid="finish-review"`: `BatchReview.spec.ts:9`, `GeneralRequest.spec.ts:10`, `PendingDrafts.spec.ts:6`.
- **Single-comment tests:**
  - UI specs click or tab to "Add single comment" through `addSingleComment` and `keyboardComment` (`tests/ui/support/codeView.ts:93,100`). The specs are LineComment, RangeComment, FileComment, ExactRangeBecomesSymbol, KeyboardOnlyCommenting, KeyboardCommentOnLines, LineWrap, AskForPlan, and GeneralRequest.
  - E2E: `CodeComments::addSingleComment` (`tests/Support/E2E/CodeComments.php:104`), used by `VerticalSliceTest` and `ProposalFlowTest`.
- **"Start a review" means two things:** the draft button, and the repository-page section that starts a review (`StartReviewForm.tsx:33`, `OnboardingSteps.tsx:47`). Removing the draft label ends this clash.

## Decisions

- No contract change. Q2 stays in the API for session clients, and the web UI no longer calls it. Removing Q2 is out of scope.
- One primary button, "Add comment", creates a D2 draft. Ctrl/Cmd+Enter does the same. The `open` intent goes from `commentAction.ts`.
- `FinishReviewOverlay` becomes `SubmitFeedbackOverlay`. The header button is "Submit feedback" with the count, and its submit button is "Submit feedback". The `data-testid` becomes `submit-feedback` in the component and its specs.
- "Pending drafts" and the draft edit and delete actions stay.
- D84 amends D15: new requests from the web UI are always drafts, while replies and state changes happen at once.

## Depends on

Nothing. F76 adds its fields to the dialog that F75 renames.

## In scope

- `CommentBox`, `FileRoute`, `useFileComments`, `NewRequestBox`, `commentAction.ts`, `draftView.ts`, and the renamed `SubmitFeedbackOverlay` with `ReviewLayout`.
- **Docs:**
  - `docs/user/comment.md`, `reviewing.md`, `keyboard-shortcuts.md`, and every other `docs/user` page that names "Add single comment", "Start a review" (as the draft button), or "Finish your review";
  - the tour text in `assets/tour/tourSteps.ts` if it names a button;
  - `docs/architecture.md:82,112,673,688`, D15, and the new D84;
  - `rest-api-requests.md:263`: Q2 is not used by the web UI.
- **Tests:** the UI and E2E helpers and specs listed in Facts.

## Out of scope

- Reply drafts in the UI.
- Removing Q2.
- A "Submit feedback" button on the request page (`/requests/:id`).

## Acceptance tests

- **UI:**
  - A new comment box shows "Add comment" and no "Add single comment".
  - Ctrl/Cmd+Enter makes a draft, and the mocked API receives no Q2.
  - The draft reaches the API only with "Submit feedback" (D5).
  - The general request box behaves the same.
- **UI helpers:** `addSingleComment` and `keyboardComment` in `tests/ui/support/codeView.ts` become add-and-submit helpers with clear names. The specs keep their assertions.
- **E2E:** `CodeComments::addSingleComment` adds and submits. `VerticalSliceTest` and `ProposalFlowTest` keep their flow.
- **Journeys:** the comment box and the Submit feedback captures.

## Verification

- `make check` is green.
- `rg -n "Add single comment|Finish your review" assets docs/user tests` finds nothing.
