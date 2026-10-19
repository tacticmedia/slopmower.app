# Webhooks, fetches, and live updates

This file specifies the GitHub webhooks, the fetches that make commits available in the server clone, and the Mercure live updates of the web UI. Conventions and repository sources are in [README.md](README.md).

## 1. GitHub webhooks

| Item | Value |
|---|---|
| URL | `POST https://<host>/webhook/github`: the route prefix `/webhook` of the `symfony/webhook` recipe in `config/routes/webhook.yaml`, type `github`. This is the webhook URL of the GitHub App and of its GitHub Marketplace listing, with the same secret. |
| Wiring | `framework.webhook.routing.github: {service: App\Webhook\GitHubRequestParser, secret: '%env(string:default::GITHUB_APP_WEBHOOK_SECRET)%'}`. An unset variable resolves to an empty secret. The consumer is `#[AsRemoteEventConsumer('github')]`. Route `Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage` to the `async` transport. |
| Request matcher | Method POST, `Content-Type: application/json`, and the headers `X-GitHub-Event` and `X-GitHub-Delivery`. Any other request gets 406, the Symfony default. The matcher does not check `X-Hub-Signature-256`, so a missing signature gets the 403 of the signature check. |
| Signature | `X-Hub-Signature-256` must be `sha256=` plus the lower-case hex of `hash_hmac('sha256', $request->getContent(), $secret)`. The parser compares with `hash_equals()` on the raw body, before JSON decoding. A missing or wrong signature throws `RejectWebhookException(403, 'Invalid signature.')`. An empty secret refuses every delivery with 403, because anyone can compute an HMAC with an empty key. The parser ignores the SHA-1 header `X-Hub-Signature`. |
| Parsing | Unparsable JSON, and JSON that is not an object, get 406. After verification, every delivery returns a `RemoteEvent`: name `github.<X-GitHub-Event>`, id = `X-GitHub-Delivery`, payload = the decoded body. Events that the server does not handle get the name `github.ignored`. A parser that returns `null` makes Symfony answer 406, so the parser never returns `null` for a verified delivery. |
| Response | 202 with an empty body, the `AbstractRequestParser` default, within the 10-second limit of GitHub. The work runs in the Messenger worker. |
| Duplicates | The consumer skips a delivery whose `X-GitHub-Delivery` is in `webhook_delivery (id PRIMARY KEY, received_at)`. It inserts the ID after it queues the jobs, so a failed run is retried. Every job is idempotent, so a duplicate, including a redelivery, changes nothing. Rows older than 7 days are purged. |
| Tenancy | The consumer runs with the Doctrine tenant filter off. Only `TenantFilter::suspend()` turns the filter off, in four places: the sign-in and token lookup, this webhook router, the projection rebuild, and the tenant purge. See [architecture section 4](../architecture.md). The consumer finds the tenants by GitHub ID through `Repository`, `GitHubInstallation`, `GitHubCandidate`, `User`, and `PullRequest` rows, and for `check_run` by the ID of a `PullRequest` row. Each job that it queues carries a `TenantStamp` of its tenant. `TenantContextMiddleware` sets the fail-closed tenant context before the handler runs. The one exception is `MarketplacePurchaseJob`: its `marketplace_account` record belongs to no tenant, so the job has no stamp and is in `app.tenantless_messages`. |

Events. Subscribe to `push`, `repository`, and `pull_request` in the App settings. GitHub sends `pull_request` only to an installation that accepted Pull requests: read. GitHub sends the `installation` and `installation_repositories` events to every GitHub App without a subscription, and `check_run` and `check_suite` to an installation that accepted Checks: write. Sign-in compares the installations anyway, because a delivery can fail. For each listed installation whose permissions add Pull requests: read or Checks: write, it queues the jobs of `new_permissions_accepted` after its commit. For each listed installation whose stored row was suspended and whose listing is not, it queues the jobs of `unsuspend` instead. An installation without a row before the sign-in had no permissions and no suspension. The GitHub Marketplace listing has its own webhook, which sends `marketplace_purchase`: set it to the URL above, content type JSON, the secret of the App, and Active.

| `X-GitHub-Event` | Action | What the server does |
|---|---|---|
| `ping` | none | No-op |
| `push` | any, for branches and tags | For each tenant repository with `github.repositoryId = repository.id` and `access = active`, request a fetch through `FetchRepository::request()` in the tenant context of that repository, as R5 does. The queued `FetchRepositoryJob` carries a `TenantStamp`. The fetch then does the steps of [section 2.2](#22-after-every-successful-fetch). |
| `installation` | `created` | If a user has `githubUserId = sender.id`, record the `GitHubInstallation` with `installation.permissions` for that tenant and publish `candidates_changed`. Otherwise ignore it: the next sign-in records it. An installation whose `target_type` is neither `User` nor `Organization`, for example an enterprise installation, is ignored here and at sign-in, because it holds no repositories. |
| `installation` | `deleted` | For each tenant with this installation: delete the candidates of the installation and the installation row, then mark all its repositories `access = removed`, queue a job that deletes their clones with the pin refs and their index, publish `repository` for each, and publish `candidates_changed`. Reviews stay, by decision D22. |
| `installation` | `suspend`, `unsuspend` | Set or clear `suspendedAt`. While suspended, fetches fail with `sync.lastError = "GitHub App installation suspended"`, and operations that need a fetch get `repository_unavailable` with reason `fetch_failed`. A suspended installation gets no webhook events, and its pull request reads and check runs are skipped. So `unsuspend` then queues the [first read](#pull-request-state) of each pull request that the requests of the tenant link in the `active` repositories of the installation, also of one with a read state, and the [check run](#check-run) job of each such pull request with a record. `suspend` queues nothing. |
| `installation` | `new_permissions_accepted` | For each tenant with this installation, store `installation.permissions` on its `GitHubInstallation`. Then queue the jobs that a missing permission skipped, for the pull requests that the requests of the tenant link in the `active` repositories of the installation. When the stored permissions add Pull requests: read, queue the [first read](#pull-request-state) of each such pull request without a read state. When they add Checks: write, queue the [check run](#check-run) job of each such pull request with a record, and the first read of each such pull request without a read state, whose new head SHA queues its check run. |
| `installation_repositories` | `added` | For installations where `installation.account.type = User` and `installation.account.id` equals the `githubUserId` of a tenant user, add the repositories to the candidate list of that tenant and publish `candidates_changed`. The payload has no default branch or HTML URL, so the server reads each repository with `GET /repos/{owner}/{repo}` and an installation token. Organization installations refresh at the next sign-in, because user access cannot be checked without the user token. |
| `installation_repositories` | `removed` | For each repository in `repositories_removed`: remove it from the candidates, then set `access = removed`, queue a job that deletes the clone and the index, and publish `repository` and `candidates_changed` |
| `repository` | `renamed`, `transferred` | Update `Repository.name` to `repository.full_name` and publish `repository`. The old name answers `not_found`. If another repository of the tenant has the new name, compared case-insensitively, the name does not change. |
| `repository` | `deleted` | As `installation_repositories.removed` |
| `repository` | others, for example `privatized`, `publicized`, `edited` | Update `github.private` when it changed, otherwise no-op |
| `pull_request` | any | For each tenant with a pull request record of `repository.id` and `pull_request.number`, queue a job that updates the record from the `pull_request` object, without an API call. See [pull request state](#pull-request-state). For `opened`, `reopened`, and `synchronize`, each other tenant with a repository of `github.repositoryId = repository.id`, `access = active`, and `github.checkEveryPullRequest` on gets a neutral check run job, see [check run](#check-run). |
| `check_run` | `rerequested` | When `check_run.external_id` is the ID of a pull request record of a repository with `github.repositoryId = repository.id`, queue a check run job for the tenant of the record that creates a new run. Otherwise queue a neutral check run job on `check_run.head_sha` for the tenant that an `external_id` `tenant:<tenant ID>` names, or, when the `external_id` names no active tenant, for each tenant whose GitHub user has the login in `check_run.name`, each only with an active repository of `repository.id` and `github.checkEveryPullRequest` on. See [check run](#check-run). |
| `check_run` | others | No-op |
| `github_app_authorization` | `revoked` | Logged. The server never stores the user token, so there is nothing to revoke. |
| `marketplace_purchase` | `purchased`, `changed` | Store the plan of `marketplace_purchase.account.id` in its `marketplace_account` record, and clear the pending change. See [Marketplace plans](#marketplace-plans). |
| `marketplace_purchase` | `pending_change` | Store `marketplace_purchase.plan.id` and `effective_date` as the pending change of the record. Without a record, nothing changes, and the next sign-in reads the plan. |
| `marketplace_purchase` | `pending_change_cancelled` | Clear the pending change of the record |
| `marketplace_purchase` | `cancelled` | Delete the record, so the account has the free plan. GitHub does not uninstall the App. For a `User` account with a user of that `githubUserId`, also queue the cancellation job for the tenant of the user, delayed by 30 days. |
| anything else, for example `check_suite` | any | `github.ignored`: no-op |

### Pull request state

- **Record.** A tenant-owned `pull_request` record per repository and number holds the title, `state` (`open` or `closed`), `merged`, `draft`, the head SHA, the full name of the head repository, and the GitHub `updated_at`. `merged` is true when `merged_at` is set, because the `pull_request` object of some actions has no `merged`. `Request.pullRequests` shows `draft` when `state` is `open` and `draft` is true, `merged` when `state` is `closed` and `merged` is true, else `state`, and `unknown` without a read state.
- **First read.** A [Q17](rest-api-requests.md#q17-link-a-pull-request-to-requests) that writes a link on a GitHub repository queues a job. So do an `installation` `new_permissions_accepted` and a sign-in that add Pull requests: read or Checks: write, for each linked pull request without a read state, and an `installation` `unsuspend` or a sign-in that clears a stored suspension, for each linked pull request. The job creates the record of a new number and reads `GET /repos/{owner}/{repo}/pulls/{number}` with an installation token for the repository and `pull_requests: read`. When the repository has lost its access, or its installation is suspended or lacks Pull requests: read in its `permissions`, the job requests no token and the state stays `unknown`. A 404 leaves the record unchanged.
- **Updates.** Each `pull_request` delivery updates the record from its `pull_request` object. A payload or response with an older `updated_at` than the record changes nothing, so the order of deliveries and reads does not matter.
- **Live update.** A changed state or title publishes one `pull_request` update per review with requests that link the number.
- **Tokens.** Each installation token asks for the permissions of its job: `contents: read` for a clone, a fetch, and a repository read, `pull_requests: read` for a pull request read, and `checks: write` for a check run.

### Check run

- **Run.** One check run per pull request record, on its head SHA, named `Slopmower (<login>)` with the GitHub login of the tenant's user. Each tenant has its own check, so the run of one tenant never hides the unfinished requests of another. A tenant without a GitHub user gets no run. `external_id` is the ID of the record, and `details_url` is the request list `/reviews/{id}/requests` of the review with the newest `pull_request.linked` event that still links the number. The record keeps the ID of its newest run, the head SHA of that run, a hash of its name, status, and annotations, and its creation time.
- **Requests.** A request is finished when it is `verified`, `wont_do` set by the user, or in a closed review. Each other linked request is unfinished.

  | Linked requests | `status` | `conclusion` | `output.title` |
  |---|---|---|---|
  | At least one unfinished | `in_progress` | none | `N of M requests finished` |
  | All finished | `completed` | `success` | `All requests finished` |
  | None | `completed` | `neutral` | `No linked requests` |

- **Summary.** The linked requests per review, with `N of M finished` per review and a link to each request. It names the requests without an annotation and says why. It lists requests until 65,535 characters and then counts the rest.
- **Content.** Everyone who can see the pull request can read its check runs, and no call deletes one, so a run holds request headlines and links, never request text. The headline is the title of the request, or the first line with text of its body while it has no title, cut to 80 characters.
- **Annotations.** One per unfinished request with an anchor, at its location at the head SHA, with the headline as `title` and the state and the link to the request as `message`: `warning` for `open`, `reopened`, and `in_progress`, and `notice` for `ready_for_review` and a `wont_do` that an agent set. A general request and an `orphaned` location get none. A file location, or one without a range, takes line 1.
- **Writes.** `POST /repos/{owner}/{repo}/check-runs` creates a run with the first 50 annotations, and `PATCH /repos/{owner}/{repo}/check-runs/{id}` adds each further 50 with the same output. An update appends annotations, no call removes one, and the REST description names no update from `completed` back to `in_progress`. GitHub sets the conclusion `stale` on a run that is incomplete for more than 14 days, and an update keeps the name. So a job creates a new run when the record has no run, when the head SHA, the name, the status, or the annotations differ from those of the run, when the run is older than 14 days, and for `rerequested`. Otherwise it updates `status`, `conclusion`, `details_url`, and `output` of the run without annotations. The record takes a new run after its last call, so a failed job leaves the previous run in the record.
- **Head.** A head SHA that the clone does not have gets one fetch, unless the head repository differs from the repository or is deleted: a fetch brings no head of a fork. A head that is still missing, also after a fetch that `RateLimited` refuses, gives a run without annotations, and the summary says why.
- **Triggers.** The events `pull_request.linked`, `pull_request.unlinked`, `request.ready_for_review`, `request.verified`, `request.reopened`, `request.wont_do`, `request.retitled`, and `review.closed`, after their commit, for the records of the pull requests that their requests link; a review deletion, after its commit, for the records that its requests linked; a record update that changes the head SHA or changes the state to `open`; `installation` `new_permissions_accepted` and a sign-in that add Checks: write; `installation` `unsuspend` and a sign-in that clears a stored suspension; and `check_run` `rerequested`. One job per record: a trigger sets a flag on the record and queues the job with a 5-second `DelayStamp`, a trigger while the flag is set queues nothing, and the job clears the flag first.
- **Unlinked pull requests.** Only for a repository with `github.checkEveryPullRequest` on, which [R8](rest-api.md#r8-check-every-pull-request) sets and which is off by default. Off, the check of a user covers only the pull requests that its requests link, and no neutral run is posted. For `pull_request` `opened`, `reopened`, and `synchronize`, each tenant with such a repository of `repository.id` and without a record of the number gets a neutral check run job, which reads the value again. It creates a run `completed` with `neutral` on `pull_request.head.sha`, named for the tenant's user, with the `external_id` `tenant:<tenant ID>` and the title and summary `No linked requests`, without `details_url` or annotations, and creates no record. So the required check of a user does not block a pull request that the user does not link. A check run belongs to a commit, so the job posts nothing when the tenant has a record of the number or an open record on the head SHA: a link since the delivery, or another pull request on the same head, has a run that the neutral run must not override. The job reads the records under the installation lock, so a record whose run came meanwhile counts. A later link creates the record and its own run, which is newer. A `rerequested` neutral run, or run whose record is gone, for example after a deletion of the repository data or of the tenant, gets the neutral run of the tenant that its `external_id` or its name names, on its head SHA.
- **Skips.** A job requests no token for a closed pull request, a record without a head SHA, a repository without access, a tenant without a GitHub user, and an installation that is suspended or lacks Checks: write in its `permissions`. Local repositories have no record.
- **Series.** A job holds the lock `check-run:<record ID>` from before it clears the flag until after its last write, so an older job never writes after a newer one. Its GitHub calls also hold the lock `github-installation:<installation ID>`, so the calls of one installation go in series.

### Marketplace plans

- **Record.** A `marketplace_account` record per GitHub account ID, user or organization, holds the Marketplace plan ID, the billing cycle, the end of a free trial, and the pending plan ID with its effective date. It belongs to no tenant, because one account can be the user of one tenant and an installation account of others. The purge of a tenant deletes the record of the account of its user, unless another tenant has that account as an installation account.
- **Plans of the instance.** The instance maps each Marketplace plan ID to a plan with a name, a rank, and the values of the [usage limits](README.md#usage-limits). The free plan has rank 0. An account without a record has the free plan. A record whose plan ID the instance does not map counts as the free plan, and the server logs an error.
- **Plan of a tenant.** The plan with the highest rank among the record of the GitHub account of the user and the records of the accounts of the installations of the tenant. Its trial end and pending change are those of the record that gives the plan. A pending plan ID that the instance does not map, or a pending change without a plan, changes to the free plan.
- **Sign-in.** At each GitHub sign-in, after the installation listing of [R2](rest-api.md#r2-list-repository-candidates), the server reads `GET /marketplace_listing/accounts/{account_id}` with the App JWT for the user account and for each installation account. A 200 answer replaces the record with `marketplace_purchase` and `marketplace_pending_change`. A 404 deletes the record. A failed read changes nothing. So a lost delivery is repaired at the next sign-in, because GitHub does not resend failed deliveries.
- **Cancellation job.** It runs 30 days after `cancelled` of a user account, in the tenant of the user. It reads the account with the same GET, which replaces or deletes the record. When the answer is 404 and the tenant has no installation left, of any account, the job purges the tenant as [A2](rest-api.md#a2-delete-the-account) does. It counts the installations under the tenant row lock of the purge, which every installation write holds too, so an installation that commits first stops the purge. A purchase or any installation of the tenant stops it. A failed read keeps the tenant, and Messenger retries the job every hour without a limit.
- **Setup URL.** After a purchase, GitHub installs the App and opens its Setup URL `/github/installed`. A signed-out user first signs in. The sign-in returns to `/github/installed`, which signs in again, so that the installations are listed, and then shows the repositories.

## 2. Fetches and commit availability

The refspecs, the pin refs, and the meaning of "available" are in [README.md section 7](README.md#7-repository-sources-names-and-commit-availability).

### 2.1 Triggers

An MCP tool name also stands for its REST endpoint.

| Trigger | GitHub source | Local source | Mode |
|---|---|---|---|
| Connect, [R3](rest-api.md#r3-connect-a-repository) | clone | clone | asynchronous job |
| `push` webhook | fetch | none | asynchronous job |
| Manual, [R5](rest-api.md#r5-fetch-a-repository), the "Refresh" button | fetch, or clone again after a failed clone | same | asynchronous job |
| Start review, [V1](rest-api.md#v1-start-a-review), unless the `ref` is a full SHA already in the clone | fetch | fetch | synchronous, 60 s timeout |
| `link_commits` with a pending link | fetch, as a fallback | fetch | asynchronous job, debounced 5 s per repository |
| `mark_ready_for_review` with pending links | fetch | fetch | synchronous, 60 s timeout |
| `get_request` with `at` not in the clone | fetch | fetch | synchronous, 60 s timeout |

- **Single flight:** a `symfony/lock` key `fetch:<repositoryId>` makes one fetch run at a time. The lock store uses PostgreSQL advisory locks, because the `php` and `worker` containers share the clones. A request that arrives during a fetch sets a flag, and one more fetch runs afterwards.
- **Credentials:** a GitHub fetch uses a fresh installation token as `http.https://github.com/.extraheader` with `AUTHORIZATION: basic <base64>`. The token reaches git only through the environment variables `GIT_CONFIG_COUNT`, `GIT_CONFIG_KEY_<n>`, and `GIT_CONFIG_VALUE_<n>`, never through the argument list, because `symfony/process` exceptions print the arguments. The token never goes into the git configuration of the clone.
- **Local fetches:** the remote is `file://` plus the real path of the directory, with each segment percent-encoded. The `git upload-pack` command that reads the source gets `-c safe.directory=<realpath>` and `-c safe.directory=<realpath>/.git` through `--upload-pack`, because git drops the `-c` options of the fetching git on a local transport. The source directory is mounted read-only and never written.
- **Failure:** the server sets `sync.lastError` and publishes a `repository` live update. For a synchronous trigger the caller gets 409 `repository_unavailable` with reason `fetch_failed`.
- **Limits:** a synchronous trigger consumes the rate limiter `sync_fetch` of the repository, and a refusal gets 429 `rate_limited`. A clone or fetch above a size limit fails as the [usage limits](README.md#usage-limits) describe.

### 2.2 After every successful fetch

1. Set `lastFetchedAt`, clear `lastError`, and read `defaultBranch`, which is the remote HEAD, and `defaultBranchHead`.
2. If `defaultBranchHead` changed, queue `RecomputeLocations(reviewId)` for each open review. The job recomputes the default-branch location of each request with an anchor. When at least one `outdated` flag changed, it publishes `outdated` with the IDs of those requests. `outdated` means that the location at the default-branch head is `changed` or `orphaned`. This step comes before step 3: a retried fetch finds the head unchanged, so a failed step 3 must not skip it.
3. Resolve pending links. The fetch has already fetched each pending linked SHA by ID into `refs/slopmower/commits/<sha>`. For each pending `CommitLink` of the repository whose SHA is now present:
   - set it to `available`;
   - make sure that the pin ref `refs/slopmower/commits/<sha>` exists;
   - write one `commit.found` event per review, in its own transaction. The actor is `system`, and the stored payload is `{commitSha, requestIds, subject, committedAt}` for the requests linked in that review.
4. Publish `repository`, and one `events` update per review that got `commit.found`.

## 3. Live updates

| Item | Value |
|---|---|
| Hub | Built into FrankenPHP. The public URL `MERCURE_PUBLIC_URL` is `https://<host>/.well-known/mercure`, the same origin as the UI. The local development environment uses `http://localhost:8000/.well-known/mercure`. The hub URL is always set, because without it the bundle compiles `FrankenPhpHub` and console processes fail. |
| Publisher | `symfony/mercure-bundle` 0.5.0, `HubInterface::publish()`. Every update is `private: true`. |
| Subscriber authorization | The cookie that [A3](rest-api.md#a3-authorize-live-updates) sets. The JWT claim `mercure.subscribe` holds the two selectors of the tenant below. `exp` is now plus 3,600 s. Agents do not subscribe: they poll `get_updates`. |
| Base | `{base}` = `DEFAULT_URI` without a trailing slash, for example `https://slopmower.example` |
| Payloads | IDs only. The browser reloads the data through REST. Mercure carries no second copy of the data model. |

Topics:

| Topic | Selector in the JWT | Update types |
|---|---|---|
| `{base}/tenants/{tenantId}/repositories` | exact | `repository`, `repository_deleted`, `candidates_changed` |
| `{base}/tenants/{tenantId}/reviews/{reviewId}` | URI template with the tenant ID filled in and `{reviewId}` as the only variable, for example `https://slopmower.example/tenants/01K6AZZ0TENANT000000000001/reviews/{reviewId}`. It matches any review of that tenant only. | `events`, `outdated`, `index`, `pull_request` |

Update types. [live-update.schema.json](schemas/live-update.schema.json) defines the payloads.

| `type` | Payload | Published | Fixture |
|---|---|---|---|
| `events` | `{type, reviewId, seq, requestIds}` | Once per committed write transaction that wrote events. `seq` is the number of the last event of the transaction. `requestIds` holds every request that its events concern, or `[]` when it wrote only review events. | [live-update.events.json](fixtures/live-update.events.json), [live-update.events-batch.json](fixtures/live-update.events-batch.json) |
| `outdated` | `{type, reviewId, requestIds}` | After a location recompute that changed at least one `outdated` flag. `requestIds` holds the requests whose flag changed. | [live-update.outdated.json](fixtures/live-update.outdated.json) |
| `pull_request` | `{type, reviewId, requestIds}` | After a pull request read or a `pull_request` delivery that changed the state or the title of a pull request, once per review with requests that link it. `requestIds` holds those requests. | [live-update.pull-request.json](fixtures/live-update.pull-request.json) |
| `index` | `{type, reviewId}` | While the index job runs, at most once per second per review, plus the final state | [live-update.index.json](fixtures/live-update.index.json) |
| `repository` | `{type, repositoryId}` | After each `sync.state` change, fetch, access change, and rename, and after [V13](rest-api.md#v13-delete-a-review) deletes a review of the repository | [live-update.repository.json](fixtures/live-update.repository.json) |
| `repository_deleted` | `{type, repositoryId}` | At [R6](rest-api.md#r6-delete-repository-data) | [live-update.repository-deleted.json](fixtures/live-update.repository-deleted.json) |
| `candidates_changed` | `{type}` | After `installation.created`, `installation.deleted`, `installation_repositories.added`, `installation_repositories.removed`, and `repository.deleted` | [live-update.candidates-changed.json](fixtures/live-update.candidates-changed.json) |

Publishing rules:

- **When:** only after the write transaction commits. See [architecture.md](../architecture.md) section 4.
- **One transaction, one update:** a batch submission sends one `events` update for all its events. The update ID is `urn:slopmower:{tenantId}:{seq}`.
- **Failures:** the server logs a publish failure and does not retry it. The write has already committed, and the UI catches up.
- **SSE event type:** the Mercure `type` is not set, so the browser receives every update through `EventSource.onmessage`. The JSON `type` field selects the handler. Clients ignore unknown types.

Client behaviour in the web UI:

1. **Subscribe.** Call [A3](rest-api.md#a3-authorize-live-updates), then open one `new EventSource(url, {withCredentials: true})`. `url` is `hubUrl` with one `topic` parameter for the repositories topic and one for the topic of the open review, each encoded with `encodeURIComponent`. Call A3 again before `expiresAt`. The `useLiveRevalidation` hook holds the connection.
2. **Track the position.** Keep `lastSeq` per review. It comes from `events` updates, from `events[].seq` of command results, from `Review.lastEventSeq`, and from the `cursor` of the updates endpoint. Ignore an `events` update whose `seq` is not above `lastSeq`.
3. **Revalidate.** On an update, call `revalidate()` of the data router that `createBrowserRouter` of React Router 8.4.0 creates. It runs the loaders of the active routes again, and they read fresh data through REST. Only one revalidation runs at a time. Updates that arrive during a revalidation cause one more revalidation after it.
   - `events`, `outdated`, `index`, and `pull_request`: when `reviewId` is the open review.
   - `repository`, `repository_deleted`, and `candidates_changed`: always.
4. **Catch up after a reconnect.** On every EventSource `open` after an error, and when the tab becomes visible again, call `GET /api/v1/reviews/{id}/updates?cursor=<lastSeq>&includeOwn=true` until `hasMore` is false. Set `lastSeq` to the last `cursor`, then revalidate once. The UI does not rely on `Last-Event-ID` replay. Hub history is not part of the contract.
5. **Request detail.** The loader of the request detail view reads `GET /api/v1/requests/{id}`, so a revalidation also refreshes the thread and `allowedActions`. `allowedActions` depends on the caller, so it is never in a live payload.
