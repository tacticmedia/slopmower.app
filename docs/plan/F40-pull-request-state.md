# F40 Pull request state

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-09 for a deeper pull request integration. F40 reads the state of each linked pull request. F41 builds a check run on it.

## Goal

- The user sees the state of each linked GitHub pull request: open, draft, merged, or closed, with its title.
- The server knows the head SHA and the head repository of each linked pull request, for F41.

## Facts

GitHub Docs, read on 2026-10-09:

- An installation token "cannot be granted permissions that the app was not granted".
- "Updated permissions won't take effect on an installation ... until the new permissions are approved". Each account that installed the App approves a new permission. The `installation` event has the action `new_permissions_accepted`.
- The installation payloads and `GET /user/installations` carry `permissions`. The code ignores them today.
- The status code of a token request above the accepted permissions is not documented.

GitHub Docs, read on 2026-10-13:

- `GET /repos/{owner}/{repo}/pulls/{number}` returns `title`, `state` (`open` or `closed`), `merged`, `draft`, `updated_at`, `head.sha`, and `head.repo.full_name`.
- Every `pull_request` webhook payload has a `pull_request` object. The docs do not list its fields per action.

## Decisions

D64 in [architecture section 11](../architecture.md#11-decisions), and:

- **Permission and event.** The App registration gets Pull requests: read and the `pull_request` event ([architecture section 9](../architecture.md#github-app-registration-for-hosted-runs)).
- **Accepted permissions.** `GitHubInstallation.permissions` (jsonb) holds the map that GitHub sends. It is written from the sign-in listing (`src/Application/InstallationSync.php`) and from `installation` `created` and `new_permissions_accepted`. Today `new_permissions_accepted` is a no-op in `GitHubWebhookConsumer::installation()`.
- **Token per job.** `InstallationTokenProvider::forRepository()` takes the permissions of its job. The clone and the fetch keep `contents: read`. A job whose permission the installation has not accepted requests no token and is skipped, not failed.
- **Stored state.** A tenant-owned `pull_request` record per repository and number: title, state, merged, draft, head SHA, head repository full name, and GitHub `updated_at`. The first link of a number creates it.
- **When the state is read:**
  - after `link_pull_request` of a GitHub repository, in a Messenger job, with `GET /repos/{owner}/{repo}/pulls/{number}`;
  - on every `pull_request` action, for a number that has a record, from the `pull_request` object of the payload without an API call. A payload with an older `updated_at` than the record changes nothing, so the order of deliveries does not matter.
  - The label: `draft` when `state` is `open` and `draft` is true, `merged` when `state` is `closed` and `merged` is true, else `state`.
  - `pull_request` goes into `HANDLED_EVENTS` (`src/Webhook/GitHubRequestParser.php`) and into `GitHubWebhookConsumer::route()`.
- **Identity by number.** For a GitHub repository, `link_pull_request` and `unlink_pull_request` match a linked pull request by its number, so a link under an old repository name still matches after a rename. This closes the candidate "Pull request links after a repository rename". D50 and D53 change.
- **Contract.** Specs first, additive, without a v2, as D45. `Request.pullRequests`: one entry per URL of `pullRequestUrls`, in the same order, `{url, state, title}`. `state` is `open`, `draft`, `merged`, `closed`, or `unknown`. `unknown` covers a local repository, a state not read yet, a pull request that GitHub does not find, and an installation without the permission. `title` is null when `state` is `unknown`. MCP `get_request` returns it too.
- **Live update.** A changed state publishes a new live update type `pull_request` with the IDs of the linked requests, so an open view reloads them (D42). Clients ignore unknown types.
- **UI.** A state label next to each linked pull request, in `assets/routes/requests/RequestDetail.tsx` and `assets/comments/ThreadBox.tsx`, with the Primer colours of GitHub's pull request states.
- Local repositories are not affected.

## Depends on

Nothing new. F14 and F20 are done.

## In scope

- Specs: `request.schema.json` `pullRequests`, `live-update.schema.json` `pull_request`, the fixtures, `rest-api-requests.md` (Q17, Q19, and the sentence "It never reads the pull request from GitHub"), `mcp.md`, specs README sections 8 and 10, and [webhooks-and-live-updates.md section 1](../specs/webhooks-and-live-updates.md#1-github-webhooks): the subscription list and the rows `pull_request` and `new_permissions_accepted`.
- Persistence: `GitHubInstallation.permissions`, the `pull_request` entity, and the migration.
- GitHub: `InstallationPayload.permissions`, the permissions argument of `InstallationTokenProvider::forRepository()`, the read job, and the webhook route and job.
- Domain: matching by number in the decider and the reducer for GitHub pull request URLs.
- UI: the state label, the fake API, and the live update.
- Docs: FR9 in [requirements](../requirements.md#fr9-commit-links), D50, D53, D64, architecture section 9, `docs/user/reviewing.md` "Pull requests", and the candidate in `features.md`.

## Out of scope

- The check run (F41).
- Review comments on the pull request (candidate "Mirror requests as pull request review comments").
- A review of the pull request head (candidate "Review a pull request head").
- Any write to GitHub.

## Acceptance tests

- **F** `InstallationTokenProviderTest`: the token request sends the permissions of the job.
- **F** `GitHubWebhookTest`: `new_permissions_accepted` stores the permissions of each tenant installation; `pull_request` `closed` with `merged: true` updates the record of a linked number and publishes `pull_request`; a number without a record queues no job; an older `updated_at` changes nothing.
- **F** `PullRequestStateTest`: `link_pull_request` on a GitHub repository queues the read job, and the job stores the state from a recorded response; an installation without Pull requests: read requests no token and leaves `unknown`; a local repository stays `unknown`.
- **F** `PullRequestLinkTest`: after a rename of the repository, `unlink_pull_request` with the old URL removes the link.
- **Contract:** `Request.pullRequests` validates in REST and MCP, and `McpRestParityTest` holds.
- Cross-tenant: two tenants that link one pull request get separate records.
- **UI** `PullRequestLink.spec.ts`: the header and the thread show the state label, and a `pull_request` live update reloads it.

## Verification

- `make check` is green, with an empty `doctrine:migrations:diff`.
- With a real App: add Pull requests: read, accept it on the test installation, link a pull request, then merge it on GitHub. The label changes to merged without a reload.
- With a real App: request a token with `pull_requests: read` before the installation accepted it. The report records the status code and the body, and a fixture keeps them.
- With a real App: a recorded `pull_request` delivery has the fields that the read job uses. It becomes the webhook fixture.
