# F48 Agent suggestions

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-13: tell a coding agent "review this repository", the agent creates suggestions through MCP for code that makes no sense, Jan accepts or dismisses each one, and the agent then works on the accepted ones.

## Goal

- An agent starts a review or uses a named one, and opens one suggestion per finding through MCP.
- The user accepts or dismisses each suggestion in the UI.
- An accepted suggestion is an ordinary request in the existing agent loop: "Work on Slopmower review <id>".

## Facts

- The MCP instructions (`config/services.yaml` `app.mcp_instructions`, [mcp.md](../specs/mcp.md) section 2) say "Never verify, reopen, create requests, or decide on proposals". [Architecture section 7.4](../architecture.md#74-rest-api) and D47 say that agents never open requests.
- `RequestDecider::guard()` throws when an agent sends `Open`. `ReviewDecider::decide()` refuses every agent review command. V1 `ReviewController::start()` is session only (`#[IsGranted(User::ROLE)]`).
- `BlobSymbols::of()` parses a blob on demand, so a symbol anchor does not wait for the index job.
- `AnchorFactory::capture()` already checks that an anchor kind and its fields agree.
- `BundledSchemas::REQUEST_COMMAND_RESULT` exists. `Review` has no root in `app:schema:bundle`.

## Decisions

D72 in [architecture section 11](../architecture.md#11-decisions).

- **A request that an agent opens is a suggestion.** It is a request in the event log with a sub-state, as F13 proposals are, not a draft row. `request.opened` is reused with the actor `agent`, so the payload does not change. Every stored `request.opened` has a user actor, so old events read `suggestion: null`. Thread, file view, re-anchoring, outdated, and live updates work without change.
- **Sub-state, no new status.** The new property `suggestion: SuggestionState|null` holds `pending`, `accepted`, or `dismissed`. `SuggestionState` is a new closed set on a new property, so the change is additive. `RequestStatus` does not change.
- **Invariants** (`RequestState::assertConsistent()`):
  - `suggestion` is null exactly when the author is a user.
  - `pending` occurs only with status `open`.
  - `dismissed` occurs only with status `wont_do`, set by the user.
- **Commands:**
  - `suggest` (agent): new command `Suggest {anchor, body}`. It becomes `RequestOpenedEvent` with `batchId` null, `carriedFrom` null, and `requireApproval` false. `Open` stays user only.
  - `accept_suggestion` (user, suggestion pending): `requireApproval?`. When it is absent, the value does not change. The event `request.suggestion_accepted {requireApproval}` stores the resulting bool, so a replay gives the same state.
  - `dismiss_suggestion` (user, suggestion pending): `note?`. Event `request.suggestion_dismissed {note}`. The status becomes `wont_do`, with `wontDoBy` user and resolution `wont_do`.
  - A user reopen of a dismissed suggestion sets it to `accepted`.
- **`AllowedActions`:**
  - While a suggestion is pending, `claim` is refused (the suggestion gate, next to `proposalGateOpen()`). `mark_wont_do` (both actors) and `set_plan_requirement` are refused, because dismiss and the accept checkbox do these things on a suggestion. `reply` stays open to both actors.
  - `accept_suggestion` and `dismiss_suggestion` are user actions on a pending suggestion only.
- **Turn (`awaiting`):**
  - Rule 2 counts an accept as a user message: `RequestReducer::tracking()` adds `RequestSuggestionAcceptedEvent` to `$isUserMessage`.
  - Rule 3 also matches a pending suggestion.
  - So a pending suggestion awaits the user, a user question on it gives the turn to the agent, and the agent's reply gives it back.
- **Limit:** at most 500 pending suggestions per review, the limit of drafts. One more gives 422 `invalid_input`.
- **Usage limits (D32):** a suggestion is a request, so Q20 and `suggest_request` check `requests_per_review` (`RequestCommands::run()` with `$openedRequests` 1) and answer 409 `limit_exceeded`. V1 and `start_review` from an agent go through `ReviewCommands::start()`, so `review_start` and `open_reviews` apply without change. The rate limit listener covers Q20 to Q22, and `McpTools::run()` covers the two tools. Q20, 4.16, and 4.17 list `limit_exceeded`; V1 lists it already, and the MCP tools do not repeat `rate_limited` (mcp.md section 3). The `requests_per_review` row of specs README "Usage limits" and the `error.limit-exceeded.json` row get Q20.
- **V15 carry-over:** a pending suggestion is not carried. It gives `not_carried` with reason `open`, and `source.suggestion` tells the client why. The schema does not change. An accepted suggestion is carried as before.
- **An agent starts a review:** V1 accepts S and B. `ReviewDecider` allows `StartReview` from an agent; `CloseReview` stays user only. `startedBy` is the agent. A token caller needs the repository in its scope.
- **Contract:** v1 is amended in place before the first release, as D45 and D49 did. [Specs README](../specs/README.md#8-versioning-and-compatibility) section 8 item 10 gets the D72 note after the "Product name, decision D14" note.
  - New endpoints: Q20 `POST /api/v1/reviews/{reviewId}/suggestions` (B), Q21 `POST /api/v1/requests/{requestId}/accept-suggestion` (S), Q22 `POST /api/v1/requests/{requestId}/dismiss-suggestion` (S).
  - V1 gets B. Q1 and `list_requests` get the optional filter `suggestion`.
  - Open sets: `Action` gets `accept_suggestion` and `dismiss_suggestion`; `EventType` and `ThreadEntryType` get `request.suggestion_accepted` and `request.suggestion_dismissed`.
  - Q20 answers 201 `RequestCommandResult`, so the agent's context stays small.
- **MCP tools:**
  - 4.16 `start_review(repository, ref?, title?)`: output `Review`, with a new `REVIEW` root in `app:schema:bundle`.
  - 4.17 `suggest_request(review, body, anchorKind?, path?, startLine?, endLine?, symbol?)`: output `RequestCommandResult`, the existing root. Flat scalar parameters, because the SDK takes no object parameters. They go to `AnchorFactory::capture()`. With `anchorKind` null the request is general.
  - Accept and dismiss get no tool, as verify and reopen have none.
- **MCP instructions** (`mcp.md` section 2 and `app.mcp_instructions`, equal text). New step after step 2: "To review code, start_review or use the named review, read the code at its commitSha, and call suggest_request once per finding with the narrowest anchor. Do not edit code for a suggestion. A pending suggestion allows only reply_to_request." The last line: "Never verify, reopen, accept or dismiss suggestions, or decide on proposals. Open requests only with suggest_request."
- **Rollback:** none after the first suggestion event, because an older build refuses an agent `request.opened` and cannot decode the new event types.
- **Spec details** (part 1):
  - `suggestion` is a property of `RequestSummary` and `Request` only. Drafts, inputs, and stored event payloads do not get it, so "every fixture with `requireApproval`" means every `RequestSummary` and `Request` fixture.
  - `event.schema.json` does not change: its `body` description already refers to `ThreadEntry.body`.
  - Q20 also sends `Location: /api/v1/requests/{id}`, as Q2 does.
  - The Q1 `suggestion` filter takes one value. A request with `suggestion` null never matches.
  - `suggest_request`: with `anchorKind` null, `path`, `startLine`, `endLine`, and `symbol` must be null. `path` has no pattern, as in `list_requests`.
  - `REVIEW` is the last entry of `BundleSchemasCommand::ROOTS`.
  - The UI label text is "Suggestion".

## Depends on

F13 (proposals, the gate pattern), F19 (plan requirement), F47 (names).

## In scope

1. **Specs** (before code):
   - `docs/specs/schemas/`: `common.schema.json` (`Action`, `EventType`, `ThreadEntryType`, closed set `SuggestionState`); `request-summary.schema.json` and `request.schema.json` (`suggestion`); `inputs.schema.json` (`SuggestRequestInput {anchor?, body}`, `AcceptSuggestionInput {requireApproval?}`, `DismissSuggestionInput {note?}`); the body descriptions in `event.schema.json` and `thread-entry.schema.json`.
   - Fixtures: new `request.suggestion-pending.json`, `request-command-result.suggested.json`, `inputs.suggest-request.json`, `inputs.accept-suggestion.json`, `inputs.dismiss-suggestion.json`, `tests/Fixtures/events/request.suggestion_accepted.json`, `tests/Fixtures/events/request.suggestion_dismissed.json`. `"suggestion": null` in every fixture with `requireApproval`. Each new fixture registered in specs README section 11 and in `assets/api/contract-fixtures-type.test.ts`.
   - `rest-api-requests.md`: section 1 command rows, a "Suggestions" paragraph, the `allowedActions` rows; Q1 filter; Q20 to Q22.
   - `rest-api.md`: section 2 index (V1 S and B, Q20 to Q22); V1 agent callers; V15 pending suggestion not carried.
   - `mcp.md`: section 1 actor line, section 2 instructions, 4.3 `suggestion` parameter, 4.16, 4.17, the tables of sections 4, 5, and 6.
   - Specs `README.md`: section 2.2 (limit 500), 8.3 (`SuggestionState`), section 8 item 10 (D72), sections 10 and 11.
2. **Docs:** `requirements.md` (section 1, FR7, FR8, FR10, FR12); `architecture.md` (section 3 `SuggestionState`, `ChangeRequest.suggestion`, events table; section 6 mermaid edge `suggest (agent)`, command rows, rules 2 and 3, invariants; section 7.3 tool table, the "agents never open requests" wording in 7.4 and D47, 7.7 skill bullet; section 8 flow "Triage suggestions"; section 10 event count); `docs/user/skill/SKILL.md` (description, tools table, section "Review code and suggest changes", errors table); `docs/user/reviewing.md` section "Agent suggestions"; `docs/user/connect-your-agent.md` review prompt; developer guide glossary "Suggestion".
3. **Domain and persistence:** `SuggestionState`; commands `Suggest`, `AcceptSuggestion`, `DismissSuggestion`; events `RequestSuggestionAcceptedEvent`, `RequestSuggestionDismissedEvent` with `EventType` cases, codecs, and `RecordedEvent::requestIds()`; `RequestDecider` (`Suggest` arm, agent-only guard, pending count for the limit, `closedGateReason()` wording); `RequestState` (`suggestion`, `suggestionPending()`, invariants); `AllowedActions`; `AwaitingPolicy` rule 3; `RequestReducer` (`opened()` actor rule, accept, dismiss, reopen, `tracking()`); `ReviewDecider` agent start; `ChangeRequest` nullable enum column `suggestionState` in `opened()` and `project()`; `RequestStateMapper`; one migration.
4. **Application, REST, MCP:** `RequestCommands::suggest()`, `acceptSuggestion()`, `dismissSuggestion()`; Q20 in the agent request controller with anchor capture as in `RequestController::open()`; Q21 and Q22 in `RequestCommandController`; V1 for tokens with a repository scope check; Q1 filter in the request query and `RequestListQuery`; `CarryOverController::canCarry()` skips a pending suggestion at both call sites; `RequestSummaryNormalizer`, `RequestNormalizer`, `EventNormalizer`; `McpTools::startReview()`, `suggestRequest()`, and the `listRequests()` filter; `app.mcp_instructions`; `BundleSchemasCommand` root `REVIEW` and a regenerated `BundledSchemas.php`; `tests/Fixtures/mcp/tools-list.json`.
5. **UI:** types in `assets/api/types/`; `assets/requests/SuggestionLabel.tsx` where `PlanToApproveLabel` is used; `assets/requests/SuggestionActions.tsx` (Accept with "Ask for a plan first" checked by default, Dismiss with an optional note through `NoteDialog`, the send-once guard) in the code-view thread and on the request detail; `ThreadBox.tsx` captions "suggested", "accepted the suggestion", "dismissed the suggestion"; `threadView.ts`; `requestIntent.ts` and `requestData.ts`; a "Suggestions" filter on the request list (`suggestion=pending`); a second copyable prompt in `promptSnippet.ts`, "Review the code of Slopmower review <id> and suggest changes"; `tests/ui/support/fakeReview.ts` and `builders.ts`.

## Out of scope

Candidates in [features.md](features.md#candidates):

- Bulk accept or dismiss.
- A suggested diff on a suggestion. The agent proposes a diff after the claim with `propose_solution`.
- Editing the suggestion body before accepting. The user replies in the thread.
- An MCP tool for accept, dismiss, or closing a review.

## Acceptance tests

- **Unit:**
  - `AllowedActionsTest` and `TransitionMatrixTest`: a column "open, suggestion pending". Claim, `mark_wont_do`, and `set_plan_requirement` are refused. Accept and dismiss are allowed for the user only.
  - `AwaitingPolicyTest`: pending gives user; a user reply gives agent; an accept gives agent.
  - `ReducerTest`: suggest, accept with and without `requireApproval`, dismiss, reopen after dismiss.
  - `EventRoundTripTest`: the two new events.
  - The invariants.
  - `ReviewDecider`: an agent starts a review; an agent close throws.
- **Functional:**
  - `SuggestionTest`: a B token creates line, range, symbol, file, and general suggestions; a session caller gets 403 on Q20; a claim gets 409; Q21 with `requireApproval` gives `awaiting: agent` and the claim then succeeds; Q22 gives a closed `wont_do` and a reopen makes the request claimable; the 501st pending suggestion gets 422; a closed review gets 409; the Q1 `suggestion` filter works; every response validates against the schemas.
  - V1 with a B token gives 201 with an agent `startedBy`.
  - `CarryOverTest`: a pending suggestion is `not_carried`, an accepted one is carried.
- **MCP:** `McpRestParityTest` (`start_review` equals V1, `suggest_request` equals Q20, `list_requests` with `suggestion` equals Q1); `ToolsListContractTest` (17 tools); `McpCrossTenantCases`; `ClaimReleaseToolTest` (a claim on a pending suggestion); `SkillMatchesToolsTest`.
- **Cross-tenant:** Q20 to Q22 in `CrossTenantCases`.
- **UI:** `Suggestion.spec.ts`: the labels show in the list, the file header, and the thread; Accept sends Q21 with `requireApproval: true` by default and false when unchecked; Dismiss sends Q22 with the note; the labels are gone after a decision; the filter sends `suggestion=pending`. Vitest: the second prompt in `promptSnippet.test.ts`.
- **E2E:** `SuggestionFlowTest`: over MCP the agent calls `start_review` and `suggest_request`; the browser shows the suggestion live and accepts it; over MCP `get_updates` returns the request, and the agent claims, links, and marks ready.

## Verification

- `make check` is green, with `doctrine:schema:validate` and an empty `doctrine:migrations:diff`.
- Manual check by Jan: restart Claude Code and re-install the skill; tell it "Review the src/Mcp directory with Slopmower and suggest changes"; the suggestions show live; accept two and dismiss one; "Work on Slopmower review <id>" fixes only the two accepted ones.
