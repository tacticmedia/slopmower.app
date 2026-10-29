# Declarative model: proposal

Status: proposed on 2026-10-29. Step 1 is [F89](F89-request-state-as-jsonb.md), scheduled by Jan on 2026-10-29. Workflow and conventions: [README](README.md). Asked by Jan on 2026-10-28: "Can it be done simpler? Not by reducing capability, but by identifying the common denominator of different-but-similar features, and then re-using the logic to serve the same purpose. Can it be simplified by moving closer to a no-code dynamic setup rather than hardcoded columns, entities, features and business logic?"

## Answer

Yes, the product can be much simpler, but not through a no-code setup. The duplication is in the request lifecycle: each verb is one row of a table that the code unrolls by hand across about 45 files. A declarative table in code, with a few merged concepts, removes most of it. A model that is configured at run time removes no work here and breaks the agent contract.

## Evidence

Measured on 2026-10-28 and 2026-10-29 on `main`:

- **Growth.** `src` had 24,731 lines after the simplification S14 (2026-10-06) and 34,381 lines after F85. Lines per namespace after F84:

  | Namespace | Files | Lines |
  |---|---|---|
  | `App\Domain` | 149 | 5,897 |
  | `App\Application` | 65 | 4,697 |
  | `App\Mcp` | 4 | 4,432, most of it the generated `BundledSchemas.php` |
  | `App\Controller` | 72 | 3,775 |
  | `App\Entity` | 25 | 2,706 |

- **One verb spreads over about 45 files.** The files that name the `verify` action: 12 in `src`, 7 in `assets`, 4 in `docs/specs`, and 22 in `tests`. A verb needs:
  - in the domain: an `Action` case, a command class, an event class with its codec, an `EventType` case, three arms in `RequestDecider` (violations, action, event), an arm in `AllowedActions`, an arm in `RequestReducer` and its turn rules, and `RequestState` fields with their invariants;
  - in persistence: `ChangeRequest` columns, a parameter of `ChangeRequest::project()` (17 today), `RequestStateMapper` (103 lines) in both directions, and a migration;
  - in the adapters: a controller action, an Input DTO, an MCP tool method, and the normalizers;
  - in the contract: the endpoint section, the endpoint index row, the MCP tool section, the JSON Schema definition, and fixtures;
  - in the UI: TypeScript types, `allowedActions.ts`, `requestIntent.ts`, and the buttons;
  - about 10 test classes, for example `TransitionMatrixTest`, `AllowedActionsTest`, `ReducerTest`, `EventRoundTripTest`, `InputFixturesTest`, `McpRestParityTest`, and the cross-tenant cases.
- **Totals of that spread.** 21 command classes (479 lines), 29 event files (1,166 lines), and 43 Input DTOs (1,081 lines). 18 `Action` cases and 24 event types.
- **Cost per feature.**
  - F58, an optional request title: 163 files, +2,014 lines.
  - F48, agent suggestions: 134 files.
  - F76, delivery choices, is planned. It adds review event fields, a proposal decision field, reducer, snapshot, projection, normalizer, and payload reader changes: the same spread again.
- **The rules are written down more than once.** The command rules are in [architecture section 6](../architecture.md#6-lifecycle) and [rest-api-requests.md section 1](../specs/rest-api-requests.md#1-commands-and-the-state-machine). The tools are in [architecture section 7.3](../architecture.md#73-mcp-server) and [mcp.md section 4](../specs/mcp.md), and `tests/Fixtures/mcp/tools-list.json` repeats `mcp.md` once more.

## Why not no-code

A model that is configured at run time keeps states, transitions, or fields in the database or an admin screen: EAV, or a workflow engine. It does not fit here:

- **No one would configure it.** A tenant has one user, and that user is not a workflow designer. The person who changes the model is the developer, so the configuration is code in any case, without the checks of code.
- **The agent contract is the product.** [Specs section 8](../specs/README.md#8-versioning-and-compatibility) closes `RequestStatus`, `Awaiting`, `ProposalState`, and the other sets, so that agents and the skill can rely on them. A workflow per tenant makes the MCP contract change per tenant, and the skill cannot teach it.
- **The event log needs fixed meanings.** Stored events are never rewritten (D37). If the definition can change, each event needs the version of the definition that wrote it. That is more code, not less.
- **The rules are code.** The proposal gate, actor equality, the 500-suggestion limit, and commit availability in git cannot be expressed as data without a rule language. PHPStan level 8 and `RequestState::assertConsistent()` would see only arrays.

The codebase already uses the right middle ground where it worked: a symbol language is a grammar, a query, and a row of a language table (D56), and a plan is a JSON value (D65).

## Steps

Each step is shippable on its own, with `make check` green, and each keeps every capability. Steps 1 and 2 keep every contract. Steps 3 to 6 change the contracts, which [specs section 8.10](../specs/README.md#8-versioning-and-compatibility) allows before the first release; each lists itself there.

| # | Step | Contract | Removes |
|---|---|---|---|
| 1 | Projection state as jsonb | none | `RequestStateMapper`, the 17 parameters of `project()`, a migration per state field |
| 2 | One action table | none | the per-action arms of `AllowedActions`, `RequestDecider`, `RequestReducer`, and the `TransitionMatrixTest` data |
| 3 | One command path | REST | the request command controllers, their Input DTOs, and the per-action MCP methods |
| 4 | One "ask" concept | REST, MCP, events | 2 sub-state sets, 4 commands, 4 events, 4 endpoints |
| 5 | One "link" concept | REST, MCP, events | 2 commands, 2 events, 2 endpoints, 2 result schemas |
| 6 | Settings as jsonb per scope | REST | one column and one endpoint per switch |

### 1. Projection state as jsonb

`ChangeRequest` stores the folded `RequestState` in one `state` jsonb column, written and read by one codec in the style of the event codecs. Only the columns that queries filter, sort, or join on stay: for example `status`, `awaiting`, `suggestion_state`, `outdated`, `created_at`, `updated_at`, and the anchor path. `Review` does the same for its event fields. The table is rebuilt from the log (`app:projections:rebuild`), so its storage form is free to change. A new state field then needs no column, no migration, no mapper change, and no `project()` parameter.

### 2. One action table

One definition per request action, in `src/Domain/Request`: the actors, the guard on the state and the actor, the input fields with their constraints (the JSON Schema of `inputs.schema.json`), the event type, the state change, and the effect on whose turn it is (a user message, an agent event, or neither). `AllowedActions`, the guards and violations of `RequestDecider`, the deltas of `RequestReducer`, and the turn rules of `AwaitingPolicy` read it. `TransitionMatrixTest` and `AllowedActionsTest` take their cases from it. The web UI gets the label, the note rule, and the confirmation of each action from the same table, through the `Action` metadata of the specs.

### 3. One command path

REST: one endpoint, `POST /api/v1/requests/{requestId}/actions/{action}`, with the input schema of the action, in place of Q4 to Q23. Q2, Q7, Q17, and Q20 also act on several requests or create one, so they stay as they are or join a second generic endpoint on the review. MCP keeps its tool names, because the skill and the agents use them, but the tools come from the table: a service that implements `Mcp\Capability\Registry\Loader\LoaderInterface` calls `RegistryInterface::registerTool()` for each row, and `symfony/mcp-bundle` autoconfigures such a service with the tag `mcp.loader` (verified in mcp/sdk 0.8.1 and the installed bundle on 2026-10-29). That stays inside Principle A. `McpRestParityTest` then compares two views of one definition.

### 4. One "ask" concept

Today three mechanisms make a request wait for the user: a question (`question: true` and `lastAgentEventIsQuestion`), a proposal (`proposal {eventId, state}`, approve, reject, and the `requireApproval` gate), and a suggestion (`suggestion`, accept, dismiss). Each is "the agent asks, the user decides". One sub-state replaces them: `ask {eventId, kind: question|plan|suggestion, state: pending|approved|rejected}`, with one user command, `answer(askEventId, decision, note, options)`. The effect of each decision per kind is a row of the table of step 2: an approved plan opens the link gate, an approved suggestion makes an ordinary request, a rejected suggestion is `wont_do` set by the user. `awaiting` rule 3 becomes "an ask is pending". F76's choice at plan approval becomes an option of the answer. The codecs read the old event types, for example `request.proposal_approved`, into the new state, so stored events stay as they are.

### 5. One "link" concept

Commit links and pull request links do the same: link 1 to 50 requests of one review, skip pairs that exist, unlink one. One concept, `link {kind: commit|pull_request, ref}`, with the differences as rules of the kind: a pending commit blocks `mark_ready_for_review`, a pull request link is allowed in `ready_for_review` and has a check run. It fits the other hosts of D83, where a merge request is one more kind. Do it after step 4, and only if F74 confirms the host model.

### 6. Settings as jsonb per scope

`User.completedTours` (A4), `Repository.checkEveryPullRequest` (R8), and the delivery choice of F76 are switches of one scope each. One `settings` jsonb per scope, with a JSON Schema, and one `PATCH` per scope, replace a column and an endpoint per switch.

### Rejected

- **Drafts as events.** A draft is edited and deleted, and the log is append-only, so the draft stays a row.
- **Generic entities for the GitHub integration.** `GitHubInstallation`, `GitHubCandidate`, `PullRequest`, and `MarketplaceAccount` follow the GitHub API, whose rules are specific. A generic form would only hide them.

## Order

The rule "bugs and issues before features" puts the open audit findings first, F86 and F87. Then steps 1, 2, 3, and 4, before F76, because F76 otherwise adds another change of about 150 files to the pattern that step 4 removes. Steps 5 and 6 follow F74 and F76. Jan decides the schedule.

## Estimate

Not measured: a new request verb would touch about 10 to 15 files in place of about 45, and `src` would lose about 2,500 to 3,500 lines, with similar cuts in `assets`, `tests`, and `docs/specs`. Record an S00-style baseline before step 1 and the measures after each step, as [simplification.md](simplification.md) did.

## Risks

- Steps 3 to 6 change the REST contract, the MCP inputs, fixtures, the TypeScript types, and the skill. Each step must change all of them in one commit.
- Step 4 changes the event model. The codecs must read every old event type, and `EventRoundTripTest` must keep the old payloads as fixtures.
- Step 1 moves filter fields into jsonb. A filter on a field that leaves its column needs a generated column or an index on the expression; check each query of `RequestQuery`, `UpdatesQuery`, and the check run job.
- A table interpreter that grows conditions of its own becomes a rule language. Keep the guards as PHP closures in the table, not as data.

## What the proposal is based on

Read verbatim: `docs/requirements.md`, `docs/architecture.md` (with D1 to D89, the risks, and the deviations), `docs/specs/README.md` sections 1 to 8, the endpoint index of `rest-api.md`, section 1 of `rest-api-requests.md`, sections 1 and 2.1 of `webhooks-and-live-updates.md`, the goals of F74 to F76, `simplification.md`, the 25 entities, and `RequestDecider`, `RequestReducer`, `RequestState`, `AllowedActions`, `AwaitingPolicy`, `Action`, `EventType`, and one event codec. Not read verbatim: the endpoint sections of the REST specs, most of `mcp.md`, the rest of the webhooks spec, the JSON examples of the specs, `developer-guide.md`, `plan/README.md`, `docs/backlog/`, `docs/user/`, and the F01 to F84 scope files and reports other than the file counts of F48 and F58. Read those parts before step 3.
