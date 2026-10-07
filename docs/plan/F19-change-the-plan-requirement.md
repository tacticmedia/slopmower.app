# F19 Change the plan requirement

Workflow, conventions, and status: [feature track](features.md). Split from F13. Decision D52, REST Q18.

## Goal

The user switches "Ask for a plan first" on or off for a request that is already open, for example when a small request turns out to be risky.

## Decisions

- **Command.** One user command, `set_plan_requirement`, REST Q18 `POST /api/v1/requests/{requestId}/plan-requirement` (S) with `PlanRequirementInput {requireApproval: bool}`. Result `RequestCommandResult`. No MCP tool: agents read `requireApproval` and never change it.
- **When.** The user, on a request that is `open`, `reopened`, or `in_progress`, of an open review. A finished request (`ready_for_review`, `wont_do`, `verified`) keeps its value; after a reopen the user can change it again.
- **Same value.** A command that sets the current value writes no event and answers 200 with `events: []`, as a repeated claim does.
- **Events.** Two event types without payload fields, so a thread entry reads without a new property: `request.plan_required` (on) and `request.plan_waived` (off). The thread captions are "asked for a plan first" and "no longer asks for a plan". `body` is null.
- **Effect.** The reducer sets `RequestState.requireApproval`; `ChangeRequest.project()` writes it, so a rebuild recomputes it. The proposal gate (F13, `RequestState::proposalGateOpen()` after F15) uses the new value at once:
  - on, `in_progress`, no approved proposal: `link_commits`, `mark_ready_for_review`, and `link_pull_request` are refused until the user approves a proposal;
  - off: the gate opens when `proposal` is null; a pending or rejected proposal still blocks, as F13 decided.
- **Turn.** The events are no user message for `awaiting` rule 2: they change no tracking field, as `pull_request.linked` does not. The agent learns the change from `get_request` and from the `invalid_transition` message (F15).
- **Carry-over.** V15 already copies the current value of the source request.
- **Contract.** Additive, v1 amended in place as D45: `Action` + `set_plan_requirement`, `EventType` and `ThreadEntryType` + the two event types, `PlanRequirementInput`. Rollback is impossible after the first such event, as D49 states.

## Depends on

F13, F15 (`RequestState::proposalGateOpen()`). F19 starts after F15 is merged.

## In scope

- Specs: `common.schema.json` (`Action`, `EventType`, `ThreadEntryType`), `inputs.schema.json` (`PlanRequirementInput`), the `allowedActions` table and the command table of `rest-api-requests.md` section 1, Q18, `rest-api.md` index, specs README sections 8.10 (D52 row) and 11, the fixtures whose user `allowedActions` change, a new `inputs.plan-requirement.json`, event fixtures for both types.
- Domain: `Action`, `AllowedActions`, `EventType`, two event classes, command `SetPlanRequirement`, `RequestDecider`, `RequestReducer`, `RequestState::with()` shape. Persistence: `ChangeRequest::project()` takes `requireApproval`; `RequestStateMapper`. No migration: the column exists.
- Application: `RequestCommands::setPlanRequirement()`, the Q18 action in `RequestCommandController`, the input DTO, `EventNormalizer` (`body` null for both types).
- UI: `assets/requests/allowedActions.ts` gets a one-click button, "Ask for a plan first" when `requireApproval` is false and "Don't ask for a plan" when it is true; `RequestActions` sends it through `useRequestCommand`; `requestIntent.ts`, `requestData.ts` (path `plan-requirement`); `ThreadBox` captions; fake API support.
- Docs: requirements FR7 and FR10, architecture sections 3, 6, 8, and row D52, `docs/user/reviewing.md` section "Ask for a plan first", the developer guide (event count, transition test count).

## Out of scope

- Changing the requirement of a finished request.
- A note with the change.
- Turning the requirement on for many requests at once.

## Acceptance tests

- **U** `TransitionMatrixTest` and `AllowedActionsTest`: the `set_plan_requirement` row for every state and caller. `ReducerTest`: on and off fold into `requireApproval`, and no tracking field changes. `EventRoundTripTest`: both event types with fixtures.
- **F** `PlanRequirementTest` (REST): switching on while `in_progress` refuses `link_commits` until a proposal is approved; switching off opens the gate without a proposal; the same value writes no event; a bearer token gets 403. Responses validate against the schemas.
- **UI** `PlanRequirement.spec.ts`: the button sends Q18 with the new value, and the "Plan required" label follows it.
- Cross-tenant: Q18 in `CrossTenantCases`. `ProjectionConsistencyTest` writes both event types.

## Verification

- `make check` is green, `doctrine:schema:validate`, empty `doctrine:migrations:diff`.
- Manual check: switch the requirement on for an `in_progress` request in the dev app; the agent's next `link_commits` fails with the plan-first message.
