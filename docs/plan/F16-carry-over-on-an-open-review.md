# F16 Carry over on an open review

Workflow, conventions, and status: [feature track](features.md). Split from F10: V15 works on any open review, but the UI offers it only in the start-review form.

## Goal

On an open review, the user carries the open requests of another review of the same repository into it, for example after a request of the older review was released.

## Decisions

- **No contract change.** The UI calls V15 `POST /api/v1/reviews/{reviewId}/carry-over` with `{fromReviewId}`.
- **Entry point.** A "Carry over requests" button in the review header (`ReviewLayout`, `reviewActions`), only while the review is open. It opens a Primer `Dialog` in the pattern of `CloseReviewDialog`: `useFetcher`, `useSubmitOnce`, `returnFocusRef`, Cancel disabled while the write runs.
- **Sources.** The dialog lists the other reviews of the repository, open and closed, newest first, without the review itself. A closed review shows "(closed)" after its title. V15 accepts closed sources (rest-api.md V15). The list loads when the dialog opens, with V2 `GET /api/v1/repositories/{repositoryId}/reviews?state=all`, through a fetcher load as `FileTree` loads directories. The review loader does not change. Without another review, the dialog says "No other review of this repository" and has no submit.
- **Write.** The review route action gets the intent `carry_over` with the field `fromReviewId`. It returns the `CarryOverResult`, or an `ActionResult` error. After success the dialog shows the result panel in place of the form, and the review data revalidates, so the Requests tab lists the new requests.
- **Result panel.** `CarryOverPanel` moves from `assets/routes/repositories/` to `assets/review/` with its own CSS module, and the start form imports it from there. Its "Open the review" link shows only when the panel is not on that review's page (a prop).
- **Errors.** V15 answers 422 for the same review, another repository, or a closed target, 404 for a source the caller cannot see, and 409 `repository_unavailable`. The dialog shows the message with `ActionErrorMessage`.

## Depends on

F10 (merged).

## In scope

- `assets/routes/reviews/ReviewLayout.tsx`, `reviewData.ts` (intent `carry_over`), a new `assets/review/CarryOverDialog.tsx`, `CarryOverPanel.tsx` moved with its CSS, `assets/routes/repositories/StartReviewForm.tsx` (import).
- UI test support: V15 in `tests/ui/support/fakeReview.ts` or inline `api.on()` as `CarryOver.spec.ts` does; V2 from `fakeRepositories.ts`.
- Docs: `docs/user/reviewing.md` section "Carry over open requests" (replace the sentence that says a review that is already open cannot take requests), architecture section 8 Review row, the developer guide counts.

## Out of scope

- A new endpoint or a change of V15.
- Carrying between repositories, or moving drafts.
- An MCP tool.

## Acceptance tests

- **UI** `CarryOverOpenReview.spec.ts`:
  - The dialog lists the other open and closed reviews of the repository, not the review itself, and not reviews of another repository.
  - Choosing a source sends V15 with `{fromReviewId}` and shows the result panel with each outcome, without "Open the review".
  - A 422 answer shows its message in the dialog.
- `CarryOver.spec.ts` stays green with the moved panel.

## Verification

- `make check` is green.
- Manual check: on the dev app, release a request of an older open review, then carry it into the newer review from its header.
