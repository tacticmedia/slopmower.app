# F112 Drop the pull request choice of Q15

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-04: "drop pullRequest from Q15 too." It was the candidate "Drop `pullRequest` of Q15" of F111.

## Goal

An approval carries no pull request choice. The choice of "Submit feedback" (`Review.delivery`) is the only one, and `Request.delivery` shows it.

## Facts

Verified on 2026-11-04 on `main` at `6d5ca32b`:

- Q15 takes `ApproveProposalInput.pullRequest`. `RequestCommandController` answers 422 on `pullRequest` when it is true and the repository has no pull requests, and passes it through `ApproveProposal` into `RequestProposalApprovedEvent.pullRequest`.
- `RequestReducer` stores it as the third field of `Proposal`, which `RequestState` writes into the `proposal` of the projection. `RequestState::delivery()` puts it before `Review.delivery.pullRequest`, and `RequestNormalizer` emits the result as `Request.delivery`.
- No code in the repository sends it since F111. `PayloadReader` ignores unknown fields, so stored events and stored projection state that hold `pullRequest` still decode without it.
- The server instructions (step 8), `docs/specs/mcp.md`, and `SKILL.md` tell the agent to read `get_request` again after an approval, because the approval can set `delivery.pullRequest`.

## Decisions

- D108: Q15 has no input `pullRequest`. `request.proposal_approved` writes no `pullRequest`, and readers ignore the field of stored events. `Proposal` has no pull request field. `Request.delivery` equals `Review.delivery`. v1 is amended in place (specs README section 8, item 10).
- `RequestState::delivery()` goes, and `RequestNormalizer` emits the delivery of the review.
- A stored approval with `pullRequest` no longer changes `Request.delivery`. The server has no release yet, so no migration keeps it.
- The agent instructions drop the extra `get_request` after an approval. The read before the claim stays.

## Depends on

[F111](F111-remove-pull-request-checkbox.md).

## In scope

- Back end: `ApproveProposalInput`, `RequestCommandController`, `ApproveProposal`, `RequestProposalApprovedEvent`, `RequestDecider`, `RequestReducer`, `Proposal`, `RequestState`, `RequestNormalizer`, `config/services.yaml`, the generated `BundledSchemas`.
- Specs: `inputs.schema.json`, `request.schema.json`, `inputs.approve-proposal.json`, `rest-api-requests.md`, `mcp.md`, specs README section 8; `docs/architecture.md` (event table, D85, D108).
- Front end: `assets/api/types/inputs.ts`, `tests/ui/support/fakeReview.ts`.
- `docs/user/skill/SKILL.md` and its copy `.claude/skills/slopmower/SKILL.md`.
- Tests: `EffectiveDeliveryTest`, `DeliveryTest`, the event fixture `request.proposal_approved.json`.

## Out of scope

- `Review.delivery`, D5, and the "Pull request" field of "Submit feedback".
- Rewriting stored events.

## Acceptance tests

- Functional: after an approval, Q3 and `get_request` show the delivery of the review.
- Functional: a local repository refuses `delivery.pullRequest` true on D5. The Q15 part of that test goes.
- Unit: the event round trip of `request.proposal_approved` without `pullRequest`.

## Verification

- `make check` is green.
- An extra `pullRequest` in a Q15 body is checked once by hand and its result is recorded in the report.
