# F90 One request command path

Workflow, conventions, and status: [feature track](features.md). Revised step 2 of the [declarative model](declarative-model.md#re-assessment-after-step-1), after [F89](F89-request-state-as-jsonb.md).

## Goal

A command on one request whose whole effect is its events runs through one method of `RequestCommands`. A new command of that kind needs no new method there, in a controller helper, or in an MCP helper. Every contract stays as it is.

## Facts

Verified on 2026-10-29:

- 13 methods of `RequestCommands` have the body `return $this->run($this->reviewOf($command->requestId), $command, $actor);`: `reply`, `claim`, `release`, `markWontDo`, `verify`, `reopen`, `propose`, `approveProposal`, `rejectProposal`, `setPlanRequirement`, `acceptSuggestion`, `dismissSuggestion`, and `setTitle`. Their commands each have `public RequestId $requestId`.
- The other methods do more: `open` and `suggest` check the request limit, `linkCommits`, `unlinkCommit`, and `markReadyForReview` work with git, and `linkPullRequest` and `unlinkPullRequest` build their commands with the repository.
- The 13 methods have 19 callers in `src` (the REST controllers and `McpTools`) and about 40 in the tests.

## Decisions

- **Marker interface.** `EventOnlyCommand extends RequestCommand` declares `public RequestId $requestId { get; }`. The 13 commands implement it.
- **One method.** `RequestCommands::execute(EventOnlyCommand, Actor)` replaces the 13 methods. The others keep their own methods, so a command with git work cannot skip it, and PHPStan checks that.

## Depends on

[F89](F89-request-state-as-jsonb.md).

## In scope

`src/Domain/Request/Command/` (the interface and 13 `implements`), `src/Application/RequestCommands.php`, its callers in `src` and `tests`, `docs/developer-guide.md`.

## Out of scope

- The REST paths, the MCP tools, and every other contract.
- Steps 3 to 6 of the declarative model.

## Acceptance tests

The existing tests of the 13 commands pass through `execute()`. PHPStan refuses a command with git work at `execute()`, which needs no runtime test.

## Verification

`make cs`, `make lint`, `make stan`, `make test`, `make front`, `make ui`, and `make e2e`. The metrics against F89.
