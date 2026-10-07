# F15 Proposal follow-ups

Workflow, conventions, and status: [feature track](features.md). Three follow-ups from the [F13 report](reports/F13.md). No contract change: the specs schemas and fixtures stay as they are.

## Goal

A pending proposal is visible at once in the file view. Each proposal in a thread keeps the decision it got. An agent that a closed proposal gate refuses reads why in the error message.

## Decisions

- **"Plan to approve" label.** One component, `assets/requests/PlanToApproveLabel.tsx`, replaces the inline label of `RequestList.tsx` and is used in two more places:
  - the thread header of the code view (`ThreadBox` with `showHeader`), after `OutdatedLabel`;
  - the file header, when at least one thread of the file has a pending proposal. `FileHeader` gets the label next to the path, not among the action buttons.
  - `ThreadView` gets `proposalPending: boolean`, from `request.proposal?.state === 'pending'`. The file loader already reads Q3 for each thread, so no new API call is needed.
  - The file tree shows no label: it has no request data per path, and adding it needs a contract change.
- **The state label of a proposal entry** (`proposalView()` in `assets/comments/threadView.ts`), first match wins:
  1. The current proposal (`request.proposal.eventId`): its state, "Waiting for approval", "Approved", or "Changes requested".
  2. A `request.proposal_approved` or `request.proposal_rejected` entry after it and before the next `request.proposed` entry: "Approved" or "Changes requested". A decision always names the pending proposal, which is the latest earlier proposal, so the thread order is enough.
  3. A later proposal exists: "Superseded".
  4. Otherwise: "Withdrawn".
- **The `invalid_transition` message** names a closed proposal gate. When the caller holds the request, the request is `in_progress`, the action is `link_commits`, `mark_ready_for_review`, or `link_pull_request`, and the gate is closed, the message ends with the reason:
  - pending: "the proposal waits for the user's decision";
  - rejected: "the user requested changes, so propose again";
  - no proposal and `requireApproval`: "the request asks for a plan first, so propose a solution".
  - Example: "The request is in_progress, so link_commits is not allowed: the proposal waits for the user's decision."
  - `details` keeps its shape. The message is English text that the contract does not fix (specs README section 4).
  - The gate moves into `RequestState::proposalGateOpen()`, so `AllowedActions` and `RequestDecider` use one rule.

## Depends on

F13, F14 (merged).

## In scope

- `assets/requests/PlanToApproveLabel.tsx`, `RequestList.tsx`, `comments/ThreadBox.tsx`, `comments/threadView.ts` (`proposalPending`, the state rule), `review/FileHeader.tsx`, `routes/reviews/FileRoute.tsx`.
- `src/Domain/Request/RequestState.php` (`proposalGateOpen()`), `AllowedActions.php`, `RequestDecider.php`, `InvalidTransition.php` (an optional reason in the message).
- Docs: `docs/user/reviewing.md` section "Ask for a plan first" (the file view label, the labels of older proposals), architecture section 8 (Review and Request detail rows), the developer guide counts.

## Out of scope

- A label in the file tree, or a Q1 filter on the proposal state.
- A new field in `details` of `invalid_transition`.

## Acceptance tests

- **Vitest** `threadView.test.ts`: the label rules, one case each: current pending, an older approved proposal of a reopened request ("Approved"), an older rejected proposal followed by a new one ("Changes requested"), an undecided older proposal ("Superseded"), and the last proposal of a released claim ("Withdrawn").
- **UI** `ProposalInFileView.spec.ts`: a line request with a pending proposal shows "Plan to approve" in the file header and in its thread header; after the approval the labels are gone.
- **U** one test with three cases: the `invalid_transition` message for a pending proposal, a rejected proposal, and a required plan without a proposal. A status refusal keeps the old message.

## Verification

- `make check` is green.
- Manual check: on the dev app, a request with a pending proposal shows "Plan to approve" in the file header.
