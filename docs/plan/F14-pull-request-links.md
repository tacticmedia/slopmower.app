# F14 Pull request links

Workflow, conventions, and status: [feature track](features.md). F14 takes the next free decision row of [architecture section 11](../architecture.md#11-decisions) when it lands. Q17 below is the next free REST ID after F13; take the next free ID at implementation time.

## Goal

An agent that opened a pull request for its work links it to one or more requests of the review. The user sees the link on the request detail and in the thread. The feature is optional: an agent that cannot create pull requests never calls it.

## Decisions

Jan, 2026-10-07: pull request links use a separate tool, which also works after a request is ready for review.

- **A separate tool,** `link_pull_request(url, requestIds, note?)`, and REST Q17 `POST /api/v1/pull-request-links` (B), shaped like `link_commits` and Q7. No unlink (candidate).
- **When:** an agent, on requests that are `in_progress` and held by the caller with the F13 gate open, or `ready_for_review`. A link never changes the status.
- **Turn:** `pull_request.linked` does not update the agent tracking, so it never flips `awaiting`.
- **URL:** `https`, at most 2,048 characters, no whitespace. For a GitHub repository it must be `https://github.com/{owner}/{name}/pull/{number}` of the review's repository, compared case-insensitively. `RequestCommands` passes the expected GitHub repository name in the `LinkPullRequest` command, so `RequestDecider` reports URL and `requestIds` violations together. A local repository accepts any `https` URL. `RepositoryNormalizer` derives `https://github.com/{name}` from `Repository.name`; nothing parses pull request URLs yet.
- **Storage:** event `pull_request.linked {url, requestIds, note}` like `commit.linked`, with a null `requestId` column. `RequestState.pullRequestUrls` and a jsonb column on `change_request`, filled by the reducer, so a rebuild recomputes them. Pairs that are already linked are skipped. No event when nothing is new.
- **Result:** `link-pull-request-result.schema.json {url, requests, events}`, with request summaries, like `link-commits-result`.
- **Contract:** additive, as F13. `Action`, `EventType`, and `ThreadEntryType` are open sets.

## Depends on

F13, for the gate.

## In scope

- Specs:
  - `Action` + `link_pull_request`; `EventType`, `ThreadEntryType` + `pull_request.linked`.
  - `request.schema.json` `pullRequestUrls`; thread entry and event `pullRequestUrl: string|null`.
  - `inputs.schema.json` `LinkPullRequestInput`.
  - The result schema and its `BundleSchemasCommand::ROOTS` entry.
  - Fixtures, and every request, thread, and event fixture gets the new properties.
  - `rest-api-requests.md` command row and Q17; `rest-api.md` index; `mcp.md` 4.14, the instructions, and the tables.
  - Specs README sections 2.2 (`url`), 8.10 (the F14 decision row), 10, 11.
- Domain: `PullRequestUrl` value object, `LinkPullRequest` command, `PullRequestLinkedEvent`, `EventType`, `RecordedEvent::requestIds()`, `AllowedActions`, `RequestDecider`, `RequestReducer` (the `apply` arm, the `assertNames` arm, no agent tracking), `RequestState`.
- Persistence: `ChangeRequest.pullRequestUrls`, migration, `RequestStateMapper`, and a `RequestProjector` loop per named request, as for `commit.linked`.
- Application: `RequestCommands::linkPullRequest()`, `RequestQuery::linkPullRequestResult()`, `EventNormalizer` (`body`, `pullRequestUrl`), `RequestNormalizer`. `PullRequestLinkController`, `LinkPullRequestInput`. `McpTools::linkPullRequest()`.
- Skill: the tools table. Section 4: "If you opened a pull request for the work, link it with `link_pull_request`. Open one only when the user or the repository's instructions ask for it."
- UI: thread caption "linked a pull request" with the link; a "Pull requests" list in the request detail header. External links use `target="_blank" rel="noreferrer"`, as `GitHubCandidates.tsx` does. Fake: `agentLinksPullRequest()`.
- Tests support: `RequestScenario`, the `AllowedActionsTest` event-type regex for `pull_request.`.
- Docs: requirements FR9 and FR12; architecture sections 3, 6, 7.3, 8, and the next free decision row; `docs/user/reviewing.md` "Pull requests"; `docs/user/connect-your-agent.md`.

## Out of scope

- Unlinking a pull request (candidate).
- Reading the pull request state from GitHub.
- Creating pull requests from the server.

## Acceptance tests

- **U** `PullRequestUrlTest`: https only, the length, the GitHub match.
- **U** `TransitionMatrixTest`, `AllowedActionsTest`, `ReducerTest`, `EventRoundTripTest`, `AwaitingPolicyTest`: the new action and event; a link on a `ready_for_review` request keeps `awaiting`.
- **F** `PullRequestLinkTest` (REST): one link names two requests and both threads show it; a repeated call writes no event; a foreign GitHub URL gives 422; a `ready_for_review` request accepts a link; an `open` request refuses it.
- **MCP** `McpRestParityTest`: `link_pull_request` equals Q17. `ToolsListContractTest`: 14 tools.
- **UI** `PullRequestLink.spec.ts`: the link shows in the header and the thread, and opens in a new tab.
- Cross-tenant: Q17 and the tool. `ProjectionConsistencyTest` writes `pull_request.linked`.

## Verification

- `make check` is green, with an empty `doctrine:migrations:diff`.
- Manual check: an agent with `gh` pushes a branch, opens a pull request, and links it. The request detail shows it.
