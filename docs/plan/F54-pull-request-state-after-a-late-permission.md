# F54 Pull request state after a late permission

Workflow, conventions, and status: [feature track](features.md). The F40 and F41 reports left a gap (candidate "Pull request state after a late permission"): a pull request linked while its installation had not accepted Pull requests: read has no state, and a pull request linked while it had not accepted Checks: write has no check run, until a later trigger.

## Goal

- When an installation accepts Pull requests: read, Slopmower reads the linked pull requests of its repositories that have no state.
- When an installation accepts Checks: write, Slopmower writes the check run of each linked pull request of its repositories.

## Facts

Verified in the code on 2026-10-15:

- The `installation` `new_permissions_accepted` delivery queues `GitHubInstallationPermissionsJob` (`GitHubWebhookConsumer`). `GitHubInstallationHandler::handlePermissions()` only records the permissions on the installation row.
- `PullRequestStateHandler::handleRead()` handles `ReadPullRequestJob`, which reads one pull request and writes its record (F40).
- `CheckRunHandler::handle()` handles `CheckRunJob`, which writes the check run of a pull request (F41).
- The `pull_request` webhook updates only numbers that have a record, and a repeated link writes no event.

## Decisions

- **Trigger.** `handlePermissions()` compares the old and the new permissions. When Pull requests: read is new, it queues a `ReadPullRequestJob` for each linked pull request of the installation's repositories that has no record. When Checks: write is new, it queues a `CheckRunJob` for each linked pull request of those repositories. The jobs are the existing jobs of F40 and F41, so the rate limits, the locks, and the retries stay the same.
- **No console command.** The webhook covers the case. An installation that accepted the permissions before F54 gets its states and check runs at the next trigger, as now.

## Depends on

Nothing new. F40 and F41 are done.

## In scope

- `GitHubInstallationHandler::handlePermissions()` and its test.
- Docs: `docs/specs/webhooks-and-live-updates.md` section 1, the `installation` row, if it lists what `new_permissions_accepted` does, and the candidate in [features.md](features.md), which F54 closes.

## Out of scope

- Pull requests of repositories that the installation no longer has.
- Fork pull requests (candidate "Review a pull request head").

## Acceptance tests

- **F** `PermissionsAcceptedTest`: `new_permissions_accepted` with Pull requests: read, new for the installation, queues one `ReadPullRequestJob` per linked pull request without a record, and none for a pull request with a record. With Checks: write, new, it queues one `CheckRunJob` per linked pull request. A delivery that adds neither queues nothing.

## Verification

- `make check` is green.
