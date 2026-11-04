# F114 Remove "Request changes" of a proposal

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-04: "I wonder what's the purpose of 'Request changes'. Why do I need to have an explicit 'Request changes' instead of just writing a response to the agent's comment". Jan chose to remove it.

## Goal

A reply asks for changes to a pending plan. "Approve" is the only decision, next to "Reply". After a reply, the page says that the agent has the turn, and the agent instructions say what to do with the reply.

## Facts

Verified on 2026-11-04 on `main` at `7ef634fe`:

- A user reply and Q16 both give the turn to the agent (`AwaitingPolicy` rule 2). Both keep the proposal gate closed (`RequestState::proposalGateOpen()`): `link_commits`, `mark_ready_for_review`, and `link_pull_request` need an approved proposal.
- Q16 also sets the proposal to `rejected`, so it cannot be approved, and shows "Changes requested". A reply keeps it `pending`, and "Approve" stays available. A new `propose_solution` replaces the current proposal in either case.
- After a reply to a pending proposal, `requestStage()` still says "The agent proposed a plan. Approve it or request changes.", and "Plan to approve" still shows (`RequestRow`, `threadView.proposalPending`), though `awaiting` is `agent`.
- The server instructions (step 8), `docs/specs/mcp.md`, and `SKILL.md` say what to do on `approved` and `rejected`, not on a user comment to a pending proposal.
- `ProposalDecision` renders "Request changes" with a `NoteDialog`. `NoteDialog` has other callers. No E2E test uses "Request changes".

## Decisions

- D110: Q16 and the action `reject_proposal` go. `request.proposal_rejected` stays a readable event type, the reducer still folds it, and `ProposalState` keeps `rejected`, so stored events and proposals keep their meaning. v1 is amended in place (specs README section 8, item 10).
- The next-step line of a pending proposal: "Waiting for the agent to answer your reply." while `awaiting` is `agent`, else "The agent proposed a plan. Approve it, or reply to ask for changes." The line of a rejected plan goes. A stored rejected plan shows "<agent> is working on this."
- "Plan to approve" shows while a proposal is pending and `awaiting` is `user`.
- Agent instructions: a user comment on a pending proposal asks for a change or a clarification. The agent answers with `reply_to_request` or proposes again. A rejected proposal: read the note and propose again.
- The candidate "Request changes takes the reply text" is dropped.

## Depends on

Nothing.

## In scope

- Front end: `ProposalEntry.tsx`, `requestStage.ts`, `RequestRow.tsx`, `threadView.ts`, `requestIntent.ts`, `requestData.ts`, `assets/api/types/{common,inputs}.ts`, the UI mock (`fakeReview.ts`, `builders.ts`).
- Back end: `RequestCommandController`, `RejectProposalInput`, `Command/RejectProposal`, `RequestDecider`, `Action`, `AllowedActions`, the generated `BundledSchemas`, `config/services.yaml`.
- Specs: `common.schema.json` (`Action`), `inputs.schema.json`, `inputs.reject-proposal.json` (deleted), `request.proposal-pending.json`, `rest-api-requests.md`, `rest-api.md`, `mcp.md`, specs README; `docs/architecture.md` (section 6, the request detail row, D49, D110); `docs/developer-guide.md`.
- `docs/user/requests.md`, both `SKILL.md` copies.
- Tests that send Q16 or build a rejected proposal through the command.

## Out of scope

- Rewriting stored `request.proposal_rejected` events.
- The `NoteDialog` of other decisions.

## Acceptance tests

- UI: a reply to a pending plan gives the turn to the agent. "Approve" stays, the next-step line says "Waiting for the agent to answer your reply.", and "Plan to approve" is gone. The decision has no "Request changes".
- Vitest `requestStage`: the pending rule for both turns.
- Unit: a stored `request.proposal_rejected` still folds to `rejected`, and the gate stays closed.
- Functional: `POST /api/v1/requests/{id}/reject-proposal` answers 404.

## Verification

- `make check` is green.
- MCP `initialize` on the dev stack has the new step 8.
