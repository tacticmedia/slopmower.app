# F20 Unlink a pull request

Workflow, conventions, and status: [feature track](features.md). Split from F14. Decision D53, REST Q19, MCP section 4.15.

## Goal

An agent removes a pull request link that it made by mistake, as `unlink_commit` removes a commit link.

## Decisions

- **Command.** `unlink_pull_request(url, requestId)`, REST Q19 `POST /api/v1/requests/{requestId}/unlink-pull-request` (B) with `UnlinkPullRequestInput {url}`. A URL does not fit a path segment, so the endpoint takes a body, unlike Q8. Result `RequestCommandResult`. MCP tool in section 4.15, 15 tools.
- **When.** The same callers and statuses as `link_pull_request` without the proposal gate, as `unlink_commit` has none: an agent on an `in_progress` request it holds, or any agent on a `ready_for_review` request. `allowedActions` lists `unlink_pull_request` only when the request has a linked pull request, as `unlink_commit` needs a link.
- **URL.** The input follows the `PullRequestUrl` rule. For a GitHub repository `PullRequestUrl::forRepository()` gives the canonical form (F14), so a URL in another letter case unlinks the stored link. A URL that is not linked to the request gives 422 `invalid_input` "Is not linked to the request.", as Q8 does.
- **Event.** `pull_request.unlinked {url, requestId}` with a null `requestId` column, as `commit.unlinked`. `RecordedEvent::requestIds()` gives `[requestId]`. The reducer removes the URL from `RequestState.pullRequestUrls`, and it is no agent event for `awaiting`, as `pull_request.linked`. The thread entry has `pullRequestUrl` set; the caption is "unlinked a pull request".
- **Contract.** Additive, v1 amended in place as D45. Rollback is impossible after the first such event.

## Depends on

F14. F20 can run in parallel with F19: both add to the same lists. Placement: F19 appends its `Action` case after `RejectProposal` and its `EventType` cases after `RequestProposalRejected`; F20 inserts `Action::UnlinkPullRequest` after `LinkPullRequest` and `EventType::PullRequestUnlinked` after `PullRequestLinked`.

## In scope

- Specs: `common.schema.json` (`Action`, `EventType`, `ThreadEntryType`), `inputs.schema.json` (`UnlinkPullRequestInput`), `event.schema.json` and `thread-entry.schema.json` descriptions (`pullRequestUrl` also for `pull_request.unlinked`, `requestIds`), the tables of `rest-api-requests.md` section 1, Q19, `rest-api.md` index, `mcp.md` (instructions, section 4 table, 4.15, section 6 table), specs README sections 8.10 (D53 row) and 11, a new `inputs.unlink-pull-request.json`, an event fixture.
- Domain: `Action`, `AllowedActions`, `EventType`, `PullRequestUnlinkedEvent`, command `UnlinkPullRequest`, `RecordedEvent::requestIds()`, `RequestDecider`, `RequestReducer` (arm, `assertNames`, no agent tracking), `RequestProjector`.
- Application: `RequestCommands::unlinkPullRequest()` (passes the repository name, as `linkPullRequest()` does), the Q19 action in `AgentRequestController`, the input DTO, `EventNormalizer`, `McpTools::unlinkPullRequest()`, `BundledSchemas.php` regenerated, `tests/Fixtures/mcp/tools-list.json`.
- Skill: the tools table.
- UI: the `ThreadBox` caption; fake API support.
- Docs: requirements FR9 and FR12, architecture sections 3, 6, 7.3, and row D53, the developer guide counts.

## Out of scope

- A user button to unlink a pull request.
- Unlinking from several requests at once.

## Acceptance tests

- **U** `TransitionMatrixTest` and `AllowedActionsTest`: the `unlink_pull_request` row for every state and caller, with a linked pull request in the scenario. `ReducerTest`, `EventRoundTripTest`: the new event.
- **F** in `PullRequestLinkTest`: unlink removes the URL from `pullRequestUrls` and adds the thread entry; an unknown URL gives 422; a GitHub URL in another letter case unlinks the canonical link; an `open` request refuses.
- **MCP** `McpRestParityTest`: `unlink_pull_request` equals Q19. `ToolsListContractTest`: 15 tools.
- Cross-tenant: Q19 and the tool. `ProjectionConsistencyTest` writes `pull_request.unlinked`.

## Verification

- `make check` is green.
- Manual check: link a pull request over REST in the dev app, unlink it, and the request header no longer lists it.
