# F111 Remove the pull request checkbox of Approve

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-04: "remove the send as pull request checkbox".

## Goal

"Approve" of a pending proposal has no "Send as a pull request" checkbox. The pull request choice is set in "Submit feedback" only, and it counts for every request of the review.

## Facts

Verified on 2026-11-04 on `main` at `9b39d785`:

- `ProposalDecision` in `assets/requests/ProposalEntry.tsx` renders the checkbox when `capabilities.pullRequests` is true. It starts from `Request.delivery.pullRequest`, and "Approve" sends its value as `pullRequest` of Q15 (D85).
- Q15 takes `pullRequest` as optional. Absent or null keeps the choice of the review. The web UI is the only sender in the repository.
- `tests/ui/Proposal.spec.ts` and `tests/ui/NextRequest.spec.ts` use the checkbox. `docs/user/requests.md`, `docs/user/comment.md`, and `docs/user/connect-your-agent.md` describe it.

## Decisions

- The web UI sends Q15 without `pullRequest`. An approval in the web UI then keeps the choice of the review.
- Q15, `request.proposal_approved.pullRequest`, `Proposal.pullRequest`, and `Request.delivery` stay as they are. Stored events keep their choice, and an API caller can still set it. D85 and the Q15 spec say that the web UI does not send it.

## Depends on

Nothing.

## In scope

- `assets/requests/ProposalEntry.tsx`.
- `tests/ui/Proposal.spec.ts` and `tests/ui/NextRequest.spec.ts`.
- `docs/architecture.md` D85, `docs/specs/rest-api-requests.md` Q15, and the three user docs above.

## Out of scope

- Removing `pullRequest` from Q15 and the event. It goes to the Candidates.
- The "Pull request" field of "Submit feedback".

## Acceptance tests

UI:

- On a GitHub review whose choice is "Open one", the decision has no checkbox, and "Approve" sends `{proposalEventId}` only.
- "Approve and go to the next one" sends `{proposalEventId}` only and opens the next request.

## Verification

- `make front` and `make ui` are green.
