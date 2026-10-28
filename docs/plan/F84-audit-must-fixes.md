# F84 Audit must-fixes

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-10-28: "fix 1, 2 and 3", the three "Must fix" findings of the repository audit of that day. The other findings of the audit are in [Candidates](features.md#candidates).

## Goal

A user who loses access to a repository on GitHub gets no new commits of it. One busy repository cannot hold every Messenger worker. A short GitHub or database outage does not move jobs to the `failed` queue.

## Facts

Verified on 2026-10-28:

- **Access.** `InstallationSync::sync()` removes access only at a sign-in. `GitHubPushHandler` fetches into every tenant that has the repository `active`, with an installation token of the installation account. When an organization removes a member, GitHub sends no `installation` or `installation_repositories` event. An API token can have no expiry. So the removed member's clone gets every push until the next sign-in.
- `GET /repos/{owner}/{repo}/collaborators/{username}/permission` needs the repository permission Metadata: read and works with installation tokens (GitHub, "Permissions required for GitHub Apps"). Its `permission` is `admin`, `write`, `read`, or `none`. A 404 means "Resource not found". The App has Metadata: read (architecture section 9). The docs of `POST /app/installations/{id}/access_tokens` do not say that a token limited by `permissions` keeps Metadata: read.
- An access removal is final: a sign-in that lists the repository again does not set it `active` (`InstallationSync::record()`).
- **Fetch queue.** `RepositorySyncHandler::handleFetch()` clears `fetchRequested` and then waits for the repository lock without a deadline. During a long fetch, each request finds the flag clear and queues one more job, and each job holds one more worker while it waits.
- **Retries.** The `async` transport retries a failed job 3 times, after 1, 2, and 4 seconds. Nothing retries the `failed` queue, and the retention job deletes its messages after 30 days.

## Decisions

- **D88 Access check at fetch.** Before each GitHub clone or fetch, the server reads the permission of the tenant user's login on the repository with the installation token of the fetch. `none` or 404 fails the fetch with `lastError` "The GitHub user has no access to this repository". It does not remove access, because a removal is final and a 404 also follows a login rename. The next sign-in removes access (D22) or, after a rename, lets the next fetch pass. The fetch token asks for Metadata: read next to Contents: read.
- **Fetch queue.** `handleFetch()` clears the flag once it holds the lock. A request during the wait reuses the waiting job, so at most one job per repository waits. A failed lock acquisition clears the flag too.
- **D89 Job retries.** The `async` transport retries a failed job 5 times, after about 30 s, 2 min, 8 min, 32 min, and 2 h 8 min, with the default jitter of 10%. A job that is still failing after about 2 h 50 min goes to `failed`. `MarketplaceHandler` keeps its own hourly retry.

## Depends on

None.

## In scope

- `src/Application/ServerClone.php`, `src/GitHub/InstallationTokenProvider.php`, `src/MessageHandler/RepositorySyncHandler.php`, `config/packages/messenger.yaml`.
- `tests/Support/GitHub/GitHubResponses.php` and fixtures, `tests/Functional/GitHub/`, `tests/Functional/Source/FetchSingleFlightTest.php`.
- `docs/architecture.md` sections 9 and 11, `docs/specs/webhooks-and-live-updates.md` section 2, `docs/deployment/manual.md`.

## Out of scope

- The Marketplace plan that a former member keeps through the installation of the organization.
- Check runs and pull request reads for a tenant that lost access.
- A login that another GitHub user takes after a rename. The check then reads that user's permission.
- The other audit findings.

## Acceptance tests

- **Functional** `GitHubAccessCheckTest`: a fetch of a user with `none` fails with the `lastError` of D88, keeps the clone and `active`, and does not fetch the new commit. A fetch of a user with `write` fetches it.
- **Functional** `FetchSingleFlightTest`: a request while a queued job waits for the lock queues no second job, and the waiting job fetches the commit of that request.
- The existing GitHub clone and fetch tests pass with the permission read.

## Verification

`make check`.
