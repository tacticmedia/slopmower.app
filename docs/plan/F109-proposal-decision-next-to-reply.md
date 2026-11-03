# F109 Proposal decision next to the Reply button

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "the 'Approve', 'Request changes' should be next to the Reply button in the comment section."

## Goal

On the request detail, the decision on a pending proposal shows in the action row of the reply box, left of "Reply", as the review buttons of a GitHub pull request. The proposal entry in the conversation keeps its state label.

## Facts

Verified on 2026-11-03 on `main` at `f4a1726f`:

- `ProposalEntry` renders `ProposalDecision` under the proposal diff on the request detail while the proposal is pending and the caller may decide: the "Send as a pull request" checkbox when the host has pull requests (D85), the "Approve" split button (F105), and "Request changes" with a `NoteDialog`.
- `ThreadBox` renders the reply box (`CommentBox`) while `reply` is allowed. `approve_proposal` is allowed only on an open review, where `reply` is allowed too.
- `CommentBox` has an `.actions` row with the submit button at the right.

## Decisions

- `CommentBox` takes an `actions` node and renders it in the action row, before the submit button.
- `ThreadBox` passes `ProposalDecision` of the pending proposal as `actions` of the reply box when `detail` and `decidable` are true. The proposal entry then shows "Waiting for approval".
- The buttons, the checkbox, the dialog, and the commands stay as they are.

## Depends on

Nothing.

## In scope

- `assets/comments/CommentBox.tsx` and its CSS module, `assets/comments/ThreadBox.tsx`, `assets/requests/ProposalEntry.tsx`.
- The UI specs of the proposal decision.
- `docs/user/requests.md`, the "Request detail" row of `docs/architecture.md`.

## Out of scope

- "Request changes" that takes the text of the reply box as its note.
- The removal of "Send as a pull request".

## Acceptance tests

UI:

- With a pending proposal on the request detail, "Approve" and "Request changes" are in the reply box, left of "Reply", and not in the proposal entry, which shows "Waiting for approval".
- The existing approve and request-changes tests pass.

## Verification

- `make front` and `make ui` are green.
