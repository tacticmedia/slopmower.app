# F13 Agent proposals

Workflow, conventions, and status: [feature track](features.md). F13 takes the next free decision row of [architecture section 11](../architecture.md#11-decisions) when it lands (D49 at `07a1bb7`). Q14 to Q16 below are the next free REST IDs at `07a1bb7`; take the next free IDs at implementation time.

## Goal

The user can mark a request "Ask for a plan first". The agent then proposes a solution in the thread, optionally with a unified diff, and links no code until the user approves. The user approves, or requests changes with a note, and the agent proposes again. An agent may also propose on any request it holds, for example when the request is unclear.

## Decisions

Jan, 2026-10-07: the approval requirement is a per-request switch that the user sets when opening a request.

- **Per-request switch.** `request.opened` gets `requireApproval: bool`. A stored event without it reads `false` (`PayloadReader` gets a bool reader with a default). Q2 and the D2 `new_request` branch take an optional `requireApproval`, default `false`, so old clients keep the old behaviour. D3 can change it on a `new_request` draft and refuses it on a reply draft with 422. The `Draft` response has it, `false` for replies. The UI checkbox "Ask for a plan first" is checked by default on new-request boxes. After the request opens, the switch is fixed. A carried request (V15) keeps the switch of its source request.
- **No new status.** The proposal is a sub-state, like a question: `proposal: {eventId, state: pending|approved|rejected} | null`. `RequestStatus` stays unchanged.
- **Gate.** `link_commits` and `mark_ready_for_review` are allowed only when `proposal` is null or approved, and approved when `requireApproval` is true. A pending or rejected proposal always blocks. The refusal is `invalid_transition`; specs README section 4 widens its meaning to "status, claim holder, or proposal state". The agent reads `proposal` in `get_request` to tell "wait" (pending) from "propose" (null or rejected).
- **The decision names the proposal.** Approve and reject carry `proposalEventId`. When a pending proposal exists and the ID differs, the answer is 422 `invalid_input` "Is not the current proposal." In every other case `AllowedActions` refuses with 409, so `AllowedActionsTest` holds. A user never approves a proposal that a newer one replaced while the page was open.
- **A proposal belongs to one claim.** `request.released`, `request.wont_do`, and `request.reopened` set it to null. After a reopen the agent proposes again.
- **Turn.** Approve and reject count as a user message for rule 2: `tracking()` sets `lastUserCommentSeq` for them, so a decision hands the turn to the agent even after an agent question.
- **Diff rules,** value object `ProposedDiff` in `src/Domain/Request/`, pure: git format, 1 to 65,536 characters, 1 to 9 `diff --git ` file headers. The skill attaches one only when the change is straightforward.
- **Diff storage.** The diff is in the event payload (jsonb). The server stores no files, so the event log stays the single source of truth.
- **Diff display:** `ProposalDiff` is a second export of `assets/code/CommitDiff.tsx`, so `@pierre/diffs` stays inside the two wrapper files. It uses `parsePatchFiles()` and `FileDiff` with the existing options and the F03 `overflow` prop. `PatchDiff` takes one file only, so it does not fit. "Download .diff" builds a Blob named `<requestId>-<seq>.diff` in the browser. No download endpoint. No new library.
- **MCP:** one new tool, `propose_solution`. Approve and reject are user-only and get no tool, like verify and reopen.
- **Contract:** v1 is amended in place, as F01 did (D45). `Action`, `EventType`, and `ThreadEntryType` are open sets. `ProposalState` is a new closed set on a new property, which is additive (specs README section 8).
- **Rollback:** none after the first proposal event, because an older build cannot decode the new event types. The F13 decision row states it, as D45 does.

## Rules

- `propose_solution`: the claiming agent, status `in_progress`. A new proposal replaces the current one, whatever its state.
- `approve_proposal`, `reject_proposal`: the user, status `in_progress`, current proposal pending. Reject needs a note.
- `awaiting`: rule 2 reads "The last user message or proposal decision is newer than the last agent event". Rule 3 reads "The last agent event is a reply with `question: true`, or a proposal is pending". Both give the results that the acceptance tests list.
- `RequestState::assertConsistent()`: a pending proposal only in `in_progress`; a non-null proposal only in `in_progress`, `ready_for_review`, or `verified`.
- `unlink_commit` keeps its rule: `in_progress`, claimed by the caller, at least one link. It is not gated.

## Depends on

F01, F03, F07, F08, F10 (all merged). M13, M16, M22, M25.

Code facts at `07a1bb7` that shape the design:

- Every permission check goes through `AllowedActions::for()`. The decider raises `invalid_transition` from it, and the same list is the API `allowedActions`. `AllowedActionsTest` requires both to agree for every action.
- `awaiting` comes from `AwaitingPolicy::evaluate()` over the tracking fields that `RequestReducer::tracking()` sets.
- `RequestReducer` drops an event without an arm silently (`default => null`). `RecordedEvent::requestIds()` decides `review_event.request_ids` once, at append time (`EventStoreSql.php`). A missing arm hides the event from every thread forever.
- `Open` has three callers: `RequestController` (Q2), `DraftController::decideDraft()` (D5), and `CarryOverController` (V15).
- Text limits are 65,536 characters. The REST body limit is 1 MiB. The MCP transport limit is 4 MiB.

## In scope

### 1. Specs, docs, and types

- `docs/specs/schemas/`:
  - `common.schema.json`: `Action` + `propose_solution`, `approve_proposal`, `reject_proposal`. `EventType` and `ThreadEntryType` + `request.proposed`, `request.proposal_approved`, `request.proposal_rejected`. New closed set `ProposalState`. `Proposal {eventId, state}`. `UnifiedDiff`, 1 to 65,536 characters.
  - `request-summary.schema.json` and `request.schema.json`: `requireApproval`, `proposal`.
  - `thread-entry.schema.json` and `event.schema.json`: `diff: string|null`. The `body` description adds proposed (plan), proposal_approved and proposal_rejected (note).
  - `draft.schema.json`: `requireApproval`.
  - `inputs.schema.json`: `OpenRequestInput.requireApproval?`, `CreateDraftInput` new_request `requireApproval?`, `UpdateDraftInput.requireApproval?`, `ProposeSolutionInput {plan, diff?}`, `ApproveProposalInput {proposalEventId, note?}`, `RejectProposalInput {proposalEventId, note}`.
- Fixtures:
  - New: `request.proposal-pending.json`, `request-command-result.proposed.json`, `inputs.propose-solution.json`, `inputs.approve-proposal.json`, `inputs.reject-proposal.json`, `inputs.open-request-approval.json`, and event fixtures `tests/Fixtures/events/request.proposed.json`, `request.proposal_approved.json`, `request.proposal_rejected.json`.
  - Changed, for `requireApproval` and `proposal`: `batch-submission.submitted`, `carry-over-result.mixed`, `link-commits-result.{available,pending}`, `lists.request-page`, `request-command-result.{claim,question,ready,release}`, `request.{carried,general,in-progress,opened-range,with-excerpt}`, `updates-page.{first-call,cursor}`, `draft.{new-request,new-general-request,reply}`, `lists.draft-list`, and `tests/Fixtures/events/request.opened{,-carried,-file,-general}.json`. Re-run `grep -l` over `docs/specs/fixtures/` for `"lastEventSeq"` and `"statusAfter"` at implementation time, because later features add fixtures.
  - Changed, for `diff`: every fixture with a thread entry or an event.
  - Register the new ones in the specs README section 11 and in `assets/api/contract-fixtures-type.test.ts`. `InputFixturesTest` substitutes `proposalEventId`, because event IDs are random.
- Spec text:
  - `rest-api-requests.md` section 1: command rows propose, approve proposal, reject proposal; a "Proposals" paragraph; the `allowedActions` rows, with the gate on `link_commits` and `mark_ready_for_review`, and `unlink_commit` written out instead of "as `link_commits`". Q2, D2, D3 bodies. New Q14 `POST /api/v1/requests/{requestId}/propose` (B), Q15 `.../approve-proposal` (S), Q16 `.../reject-proposal` (S).
  - `rest-api.md` section 2 index: Q14 to Q16.
  - `mcp.md`: section 2 instructions, the section 4 table, new 4.13 `propose_solution`, the section 6 table.
  - Specs README: section 2.2 limits (`plan`, `diff`), section 4 (`invalid_transition` meaning), section 8.3 (`ProposalState` in the closed sets), section 8.10 (the F13 decision row: the gate, the awaiting rules, the new payload field), sections 10 and 11.
- Regenerate `src/Mcp/BundledSchemas.php` with `make console c="app:schema:bundle"`.
- `assets/api/types/`: `common.ts` (actions, event types, `PROPOSAL_STATES`), `request-summary.ts`, `request.ts`, `thread-entry.ts`, `event.ts`, `draft.ts`, `inputs.ts`.
- Docs:
  - `requirements.md`: FR7 (the gate), FR8 (proposals in the conversation), FR10 (checkbox, Approve, Request changes), FR12 (operation list).
  - `architecture.md`: section 3 (`ChangeRequest`, `Draft`, `ProposalState`, the events table, the `request.opened` example payload, which `ArchitectureExamplePayloadTest` compares), section 6 (command rows, gate checks, rules 2 and 3), 7.3 tools table, 7.7 skill bullet, 8 (request detail, a flow "Approve a plan"), 10 (event count), and the next free decision row.
  - `developer-guide.md`: glossary rows "Proposal" and "Ask for a plan first".
  - `docs/user/reviewing.md`: section "Ask for a plan first".
  - `config/services.yaml` `app.mcp_instructions`, equal to `mcp.md` section 2.

### 2. Domain and persistence

- `Action`, `AllowedActions` (the gate and the three actions), `AwaitingPolicy` (rules 2 and 3), `RequestState` (`requireApproval`, `proposal`, the asserts, the `with()` array shape), `RequestReducer` (arms for the three events; release, wont_do, and reopen clear the proposal; `tracking()` for decisions).
- New enum `ProposalState`, value objects `Proposal` and `ProposedDiff`. Commands `ProposeSolution`, `ApproveProposal`, `RejectProposal`. `Open` gets `requireApproval`.
- Events `RequestProposedEvent {plan, diff}`, `RequestProposalApprovedEvent {proposalEventId, note}`, `RequestProposalRejectedEvent {proposalEventId, note}`. `EventType` cases. `RequestOpenedEvent` writes and reads `requireApproval`. `PayloadReader` gets a bool reader with a default.
- `RequestDecider`: the command-to-event arms and the validations (plan text, `ProposedDiff`, the current proposal ID).
- `ChangeRequest`: `requireApproval` (bool, default false), `proposalEventId` (nullable `EventId`), `proposalState` (nullable enum), in `opened()` and `project()`. `Draft`: `requireApproval`. One migration from `make migration`. `RequestStateMapper` maps the fields.

### 3. Application, REST, MCP, and the skill

- `RequestCommands`: `propose()`, `approveProposal()`, `rejectProposal()`.
- `requireApproval` reaches `Open` in its three callers: `RequestController` (Q2), `DraftController::decideDraft()` (D5), and `CarryOverController` (V15, from the source request).
- `EventNormalizer`: `body` and `diff` for the new events. `RequestSummaryNormalizer`, `RequestNormalizer`: `requireApproval`, `proposal`. The draft normalizer: `requireApproval`.
- Controllers: Q14 in `AgentRequestController`, Q15 and Q16 in `RequestCommandController`. Inputs `ProposeSolutionInput`, `ApproveProposalInput`, `RejectProposalInput`. `OpenRequestInput`, `CreateDraftInput`, `UpdateDraftInput` get `requireApproval`. `DraftController` stores it.
- `McpTools::proposeSolution()`: `#[Schema(minLength: 1, maxLength: 65536)]` on `plan` and `diff`, output `REQUEST_COMMAND_RESULT`. `tests/Fixtures/mcp/tools-list.json`.
- `docs/user/skill/SKILL.md`:
  - Tools table: `propose_solution` with `id`, `plan`, `diff?`.
  - Section 4, after `claim_request`: when `requireApproval` is true, or the request is unclear or large, propose first.
    - Plan only: describe the change. Do not edit code.
    - Plan with diff, when the change is straightforward and touches fewer than 10 files: start from a clean working tree, make the change, `git add -A`, attach `git diff --cached`, then `git stash push -m "slopmower <requestId>"`.
    - Call `propose_solution`, then take the next request. The decision arrives through `get_updates`.
    - On approval: `git apply --index` the approved diff from `get_request`, `git stash drop` that entry, test, commit, link, mark ready. Without a diff, implement the approved plan.
    - On "request changes": drop the stash, read the note, propose again.
  - Section 5: name the requests that wait for approval.
  - Errors table: on `invalid_transition`, read `proposal` in `get_request`. Pending: take the next request. Null or rejected: propose.

### 4. UI

- `comments/CommentBox.tsx`: an optional "Ask for a plan first" checkbox, checked by default, only for new requests (file view, `requests/NewRequestBox.tsx`, and the F01 pending-draft edit of a new-request draft). `commentAction.ts` sends `requireApproval` in Q2, D2, and D3.
- `comments/threadView.ts`: keep entries that have a body, a diff, or the type `request.proposal_approved`. `comments/ThreadBox.tsx`: captions "proposed a solution", "approved the proposal", "requested changes".
- `requests/ProposalEntry.tsx`: the plan, the lazy `ProposalDiff`, "Download .diff", and a state label:
  - The current proposal shows "Approved" or "Changes requested", or the buttons when it is pending.
  - An older one shows "Superseded" when a later proposal exists, otherwise "Withdrawn".
  - Buttons, from `allowedActions`: "Approve" (one click) and "Request changes" (`NoteDialog`, required note).
  - Each write is sent once, with the guard of `RequestActions` or `useSubmitOnce` (plan README front-end conventions).
  - In the code-view thread the diff is not rendered; a link opens the request detail.
- `assets/code/CommitDiff.tsx`: `ProposalDiff`. It needs no per-file loading, because the patch holds the lines.
- `requests/requestIntent.ts`, `routes/requests/requestData.ts`: intents and paths `approve-proposal`, `reject-proposal`.
- Request detail header: label "Plan required". Request list: label "Plan to approve" when the proposal is pending.
- `tests/ui/support/fakeReview.ts` and `builders.ts`: `agentProposes()`, the two commands, the gate in `userActions`, rules 2 and 3 in `awaitingOf`, the new properties in `threadEntry` and `requestSummary`.

## Out of scope

- A new `RequestStatus`, or `/api/v2`.
- Changing `requireApproval` after the request opens (candidate).
- Approving with a note in the UI. The API takes one.
- Editing or applying a diff in the browser. The server never writes to repositories.
- A download endpoint for the diff.

## Acceptance tests

- **U** `TransitionMatrixTest`, `AllowedActionsTest` (table and the event-type regex): the three commands for every state and caller. `link_commits` and `mark_ready_for_review` refused while a proposal is pending or rejected, and while `requireApproval` holds without an approved proposal. `RequestScenario::validCommand` covers the new actions. `ClaimReleaseToolTest` expects the new action lists.
- **U** `AwaitingPolicyTest`: a pending proposal waits for the user; a user reply gives the agent; a decision gives the agent, also after an agent question.
- **U** `ReducerTest`: propose, approve, reject, supersede; release, wont_do, and reopen clear the proposal.
- **U** `EventRoundTripTest`: the three new events with fixtures; a stored `request.opened` without `requireApproval` reads `false`.
- **U** `ProposedDiffTest`: a git diff of 9 files passes; 10 files, no `diff --git` header, or more than 65,536 characters give `invalid_input`.
- **F** `ProposalTest` (REST): open with `requireApproval`, claim, link refused, propose with a diff, approve, link, mark ready. Reject, then link refused, then propose again. A stale `proposalEventId` gives 422; approve with nothing pending gives 409. Responses validate against the schemas.
- **F** Draft tests: D2 and D3 store `requireApproval`; a submitted draft opens a request that requires approval; D3 refuses it on a reply draft.
- **F** `CarryOverTest`: a carried request keeps `requireApproval` of its source.
- **MCP** `McpRestParityTest`: `propose_solution` equals Q14. `ToolsListContractTest`: 13 tools.
- **UI** `Proposal.spec.ts`: the diff renders per file; "Download .diff" saves the text; "Approve" sends Q15 with the current event ID; "Request changes" sends Q16 with the note; an older proposal shows "Superseded".
- **UI** `AskForPlan.spec.ts`: the checkbox is checked by default, and Q2 and D2 carry `requireApproval`.
- **E** `ProposalFlowTest`: the user opens a request with "Ask for a plan first"; over MCP the agent claims and proposes with a diff; the browser shows the diff live and approves; over MCP the agent links a commit and marks ready.
- Cross-tenant: Q14, Q15, Q16, and the tool in `CrossTenantCases` and `McpCrossTenantCases`. `ProjectionConsistencyTest` writes the new event types.

## Verification

- `make check` is green, with `doctrine:schema:validate` and an empty `doctrine:migrations:diff`.
- Manual check: open a request with "Ask for a plan first". With Claude Code and the re-installed skill, the agent proposes with a diff. Approve in the browser. The agent commits, links, and marks ready.

## Risks

- An agent with an old skill copy calls `link_commits` and gets `invalid_transition`. The live tool descriptions and the server instructions mitigate it. Re-install the skill after the merge.
- Each diff in `get_request` adds to the agent's context. The 65,536-character limit bounds it.
- A lost stash does not lose the change: `get_request` holds the approved diff.
- There is no rollback after the first proposal event.
