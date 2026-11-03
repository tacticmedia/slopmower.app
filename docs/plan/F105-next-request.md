# F105 Next request on the request page

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "I want to see a 'next request' button in the request detail, which would land me at the oldest request in the same review waiting for my input. I don't want to leave the request detail just to find a new request. I think a combo button 'Approve' with a dropdown of alternative actions, the only alternative action being 'Approve and go to the next one', would work the best."

## Goal

On a request page, the user finishes a request and opens the next one that waits for them in one step, or moves on without an action. The request list is not needed in between.

## Facts

Verified on 2026-11-03:

- The "yes" decisions of the request page:
  - "Approve" of a proposed plan, Q15, in `assets/requests/ProposalEntry.tsx`, with the "Send as a pull request" checkbox when the host has pull requests (F76).
  - "Verify", the primary button of `assets/requests/RequestActions.tsx` in the sticky `ActionBar`.
  - "Accept" of an agent suggestion, Q21, in `assets/requests/SuggestionActions.tsx`, with its plan checkbox.
- Q1 `GET /api/v1/reviews/{reviewId}/requests` takes `awaiting=user`, `sort=created` (`id` ascending, the oldest first), and `limit` (`docs/specs/rest-api-requests.md`, Q1). No contract change is needed.
- The request page is the route `requests/:requestId` with `requestLoader` and `requestAction` (`assets/routes/requests/requestData.ts`). The action posts the intents of the page.
- `ButtonGroup` of Primer is in use (`assets/routes/reviews/FileRoute.tsx`).
- F104 puts the request page under the unsaved-comment guard of F93, so a move to the next request asks first when a comment box holds text.

## Decisions

Jan chose on 2026-11-03: the split menu on all three buttons, and a plain "Next request" button too.

- **The next request** is the oldest request of the same review that waits for the user (Q1 `awaiting=user&sort=created`), other than the current one.
- **Split buttons:** "Approve", "Verify", and "Accept" stay the primary buttons. Each gets a menu button beside it (Primer `ButtonGroup` with an `ActionMenu` on a `TriangleDownIcon` `IconButton`) with one item: "Approve and go to the next one", "Verify and go to the next one", "Accept and go to the next one". The item sends the same command with the same inputs (the pull request checkbox, the plan checkbox). When the command succeeds, the route action reads the next request and redirects to it. When it fails, the page stays and shows the error as today.
- **No next request:** the redirect goes to the request list of the review with "Waiting for you" (`awaiting=user`).
- **"Next request" button:** in the sticky action bar, a link to the next request, so Cmd-click opens it in a new tab. It shows only while another request of the review waits for the user. The request loader reads it with Q1 `limit=2`.

## Depends on

F104, for the unsaved-comment guard on the request page.

## In scope

- `assets/requests/ProposalEntry.tsx`, `RequestActions.tsx`, `SuggestionActions.tsx`, `ActionBar.tsx`, and a shared split-button component if the three use one.
- `assets/routes/requests/requestData.ts` (loader and action), the intent types.
- `tests/ui/support/fakeReview.ts` if Q1 needs `sort=created` with `limit`.
- `docs/user/requests.md`.

## Out of scope

- A next request across reviews.
- A keyboard shortcut.
- "Request changes", "Reopen", "Close as won't do", "Dismiss", and "Unassign" with "... and go to the next one".

## Acceptance tests

UI:

- "Approve and go to the next one" sends Q15 with the checkbox value and opens the oldest other request that waits for the user.
- "Verify and go to the next one" and "Accept and go to the next one" send their command and open the next request.
- With no other request waiting, the item opens the request list with `awaiting=user`.
- A failed command keeps the page and shows the error.
- "Next request" links to the oldest other waiting request, and does not show when none waits.

## Verification

- `make check` is green.
- Mutation check: without the exclusion of the current request, a test fails.
