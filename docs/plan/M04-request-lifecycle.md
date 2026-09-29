# M04 Request lifecycle

Workflow, conventions, and status: [README](README.md).

## Goal

Pure logic that decides commands into events or errors, reduces events into state, and derives `awaiting` and the allowed actions.

## Depends on

M03.

## In scope

- `RequestState` and `ReviewSnapshot`.
- Command DTOs: `Open`, `Claim`, `Release`, `Reply`, `LinkCommits`, `UnlinkCommit`, `MarkReadyForReview`, `MarkWontDo`, `Verify`, `Reopen`, `StartReview`, `CloseReview`.
- `RequestDecider::decide(command, ?state, review, actor, availability)`.
- `RequestReducer::apply`, `ReviewDecider`, `ReviewReducer`.
- `AwaitingPolicy`: rules 1 to 5 of architecture section 6, in order.
- `AllowedActions`, plus the derived `closed` and `resolution`.
- Rules. The source is the table in architecture section 6 and in docs/specs/rest-api-requests.md section 1:
  - A second claim by the same agent produces no event. Two actors are the same when kind, `userId`, and `agentName` match, so tokens with the same name act as one agent.
  - Another agent's claim gives `invalid_transition` with `details.claimedBy`.
  - Release restores `statusBeforeClaim`. The user may release: "Unassign", decision D6.
  - `claimedBy` and `claimedAt` are set only while the status is `in_progress`.
  - A `wont_do` reason needs 20 or more characters after trimming. The user can verify a `wont_do` only when an agent set it.
  - `mark_ready_for_review` needs every link `available`. The application passes the availability after its one fetch. Zero links are allowed.
  - `link_commits` needs every named request `in_progress` and claimed by the caller. The first request that fails gives `invalid_transition`, and nothing is written. Pairs that are already linked are skipped. When nothing is new, there is no event.
  - `unlink_commit` on a commit that is not linked gives `invalid_input`.
  - A user comment with `question: true` gives `invalid_input`. Only agents ask questions.
  - `resolution` is null for `open`, `in_progress`, and `reopened`; `fixed` for `ready_for_review` and for `verified` reached from `ready_for_review`; `wont_do` for `wont_do` and for `verified` reached from `wont_do`.
  - Any write by a user or an agent on a closed review fails with `review_closed`, decision D27. `allowedActions` is empty on a closed review. Closing a closed review gives `review_closed`.

## Out of scope

- Persistence, fetching.

## Files (representative)

- `src/Domain/Request/RequestState.php`, `RequestDecider.php`, `RequestReducer.php`, `AwaitingPolicy.php`, `AllowedActions.php`.
- `src/Domain/Request/Command/*.php`, `src/Domain/Review/*.php`.
- `tests/Unit/Domain/Request/*Test.php`, `tests/Unit/Domain/Review/*Test.php`.

## Tasks (ordered)

1. Implement the decider tables, the reducers, and the policies.
2. Write the expected outcomes in the tests literally from architecture section 6 and docs/specs/rest-api-requests.md section 1.

## Acceptance tests

All are unit tests.

- **U `TransitionMatrixTest`** proves the complete state machine, each case with its expected event or error code. The dimensions:
  - the state before the command: none for `open`, else the 6 statuses, with `wont_do` split into "set by an agent" and "set by the user";
  - the 10 request commands of architecture section 6;
  - the caller: user, claiming agent, or another agent. `system` issues no command.
  - "Command <command> by <caller> on <state> gives <outcome>", one data set per case
- **U `ClaimIdempotenceTest`** proves the claim rules.
  - "A repeated claim by the same agent gives no event"
  - "Tokens with the same agent name count as the same agent"
  - "A claim by another agent gives invalid_transition with claimedBy"
- **U `ReleaseRestoresStatusTest`** proves release and Unassign.
  - "Release restores open or reopened"
  - "The user may release a claimed request"
- **U `WontDoRulesTest`** proves the won't-do rules.
  - "Rejects a reason with fewer than 20 characters"
  - "An agent's wont_do waits for the user"
  - "A user's wont_do closes the request"
  - "The user verifies a wont_do only when an agent set it"
- **U `MarkReadyTest`** proves the availability rule.
  - "Allows zero links"
  - "A pending link gives commits_not_available with the pending SHAs"
  - "All links available gives ready_for_review"
- **U `LinkCommitsRulesTest`** proves the link rules.
  - "Skips pairs that are already linked and writes no event when nothing is new"
  - "A request that the caller does not hold gives invalid_transition and writes nothing"
- **U `ReplyRulesTest`** proves the question flag rule.
  - "A user reply with question true gives invalid_input"
- **U `AwaitingPolicyTest`** proves each awaiting rule and their order.
  - "Rule <n> gives <awaiting>", one case per rule
  - "An earlier rule wins over a later one"
- **U `ReducerTest`** proves the state after each event.
  - "Every event type updates the state fields"
  - "commit.found moves a link from pending to available"
- **U `ClosedReviewTest`** proves the closed-review rule.
  - "Every write by user or agent on a closed review gives review_closed"
  - "Reads are still allowed on a closed review"
  - "Closing a closed review gives review_closed"
- **U `AllowedActionsTest`** proves that the UI and the decider agree. It is a property test.
  - "An action is allowed if and only if decide() does not throw"
  - "The list equals the docs/specs table for agent and user callers"
  - "The list is empty on a closed review"

## Verification commands

```sh
make test
make stan
make check
```

## Done

The matrix is complete, and `RequestDecider` and `AllowedActions` agree.
