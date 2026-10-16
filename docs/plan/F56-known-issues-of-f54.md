# F56 Known issues of F54

Workflow, conventions, and status: [feature track](features.md). The F54 report and review left two gaps (candidates "Pull request states after an unsuspend" and "Permissions recorded by a sign-in"). AGENTS.md puts known issues ahead of features, so F56 runs before F57 to F60.

## Goal

- After an `installation` `unsuspend`, the linked pull requests of the installation have a current state, head SHA, and check run without waiting for a push.
- A sign-in that records a new Pull requests: read or Checks: write permission queues the same jobs as an `installation` `new_permissions_accepted` delivery.

## Facts

Verified in the code on 2026-10-16:

- `GitHubInstallationHandler::handleSuspension()` (`src/MessageHandler/GitHubInstallationHandler.php`) only calls `GitHubInstallation::recordSuspension()`. `GitHubInstallationSuspensionJob::$suspendedAt` is null for `unsuspend`.
- While an installation is suspended, it gets no webhook events, and the reads and check runs of its repositories are skipped. So after an unsuspend every linked record can be stale: an old state, an old head SHA, and no check run on the new head.
- `GitHubInstallationHandler::handlePermissions()` compares the old and the new permissions under `GitHubRepositoriesLock`, and then, for each active repository of the installation, queues a `ReadPullRequestJob` for each linked pull request without a read state (no record, or `PullRequestState::Unknown`) and, when Checks: write is new, `QueueCheckRun::request()` for each record.
- A read that sets a new head SHA queues its check run (`PullRequestStateHandler::record()`).
- `InstallationSync::record()` (`src/Application/InstallationSync.php`) runs at each GitHub sign-in under `GitHubRepositoriesLock`. It deletes every `GitHubInstallation` row of the tenant and persists the listed ones with their listed permissions. It queues nothing.

## Decisions

- **Unsuspend:** after the suspension is recorded, the handler queues a `ReadPullRequestJob` for every linked pull request of the installation's active repositories, also for one with a read state, and `QueueCheckRun::request()` for every record. A suspension (`suspendedAt` not null) queues nothing.
- **Sign-in:** `InstallationSync::record()` reads the permissions of each installation before it deletes the rows. After the lock returns, for each listed installation where Pull requests: read or Checks: write is new, it queues the jobs of `handlePermissions()` with the same rules. A new installation (no row before) counts as having had no permissions.
- **One queue method:** the loop of `handlePermissions()` moves into one public method that the handler and `InstallationSync` both call, with the options "read every pull request or only those without a read state" and "queue the check runs". Two consumers, so a class of its own is allowed if no existing class fits. The jobs, rate limits, locks, and retries stay those of F40 and F41.
- **After the commit:** the jobs are queued after `GitHubRepositoriesLock::run()` returns, as in `handlePermissions()`, so no message goes out inside a transaction.

## Depends on

F40, F41, F54 (done).

## In scope

- `GitHubInstallationHandler` (`handleSuspension()`, `handlePermissions()`), `InstallationSync`, and the shared queue method.
- Tests in `tests/Functional/GitHub/` (`SuspendedInstallationTest`, `InstallationSyncTest`, `PermissionsAcceptedTest`).
- Docs: `docs/specs/webhooks-and-live-updates.md` section 1, the `installation` rows `suspend`, `unsuspend` and the sign-in sentence, and the "First read" and "Triggers" bullets where they list what queues a read or a check run.

## Out of scope

- Pull requests of repositories that the installation no longer has.
- A console command that refreshes every pull request.

## Acceptance tests

- **F** `SuspendedInstallationTest`: an `unsuspend` queues one `ReadPullRequestJob` per linked pull request of the installation's active repositories, with and without a read state, and one check run request per record. A `suspend` queues nothing.
- **F** `InstallationSyncTest`: a sign-in whose listing adds Pull requests: read to an installation queues the reads of the linked pull requests without a read state; one that adds Checks: write also queues the check runs of the records; a sign-in with unchanged permissions queues nothing.
- **F** `PermissionsAcceptedTest` stays green without change of its assertions.

## Verification

- `make check` is green.
