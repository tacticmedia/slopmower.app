# Webhooks, fetches, and live updates

This file specifies the GitHub webhooks, the fetches that make commits available in the server clone, and the Mercure live updates of the web UI. Conventions and repository sources are in [README.md](README.md).

## 1. GitHub webhooks

| Item | Value |
|---|---|
| URL | `POST https://<host>/webhook/github`: the route prefix `/webhook` of the `symfony/webhook` recipe in `config/routes/webhook.yaml`, type `github`. This is the webhook URL of the GitHub App. |
| Wiring | `framework.webhook.routing.github: {service: App\Webhook\GitHubRequestParser, secret: '%env(GITHUB_APP_WEBHOOK_SECRET)%'}`. The consumer is `#[AsRemoteEventConsumer('github')]`. Route `Symfony\Component\Webhook\Messenger\ConsumeRemoteEventMessage` to the `async` transport. |
| Request matcher | Method POST, `Content-Type: application/json`, and the headers `X-GitHub-Event`, `X-GitHub-Delivery`, and `X-Hub-Signature-256`. Any other request gets 406, the Symfony default. |
| Signature | `X-Hub-Signature-256` must be `sha256=` plus the lower-case hex of `hash_hmac('sha256', $request->getContent(), $secret)`. The parser compares with `hash_equals()` on the raw body, before JSON decoding. A missing or wrong signature throws `RejectWebhookException(403, 'Invalid signature.')`. The parser ignores the SHA-1 header `X-Hub-Signature`. |
| Parsing | Unparsable JSON gets 406. After verification, every delivery returns a `RemoteEvent`: name `github.<X-GitHub-Event>`, id = `X-GitHub-Delivery`, payload = the decoded body. Events that the server does not handle get the name `github.ignored`. A parser that returns `null` makes Symfony answer 406, so the parser never returns `null` for a verified delivery. |
| Response | 202 with an empty body, the `AbstractRequestParser` default, within the 10-second limit of GitHub. The work runs in the Messenger worker. |
| Duplicates | The consumer inserts `X-GitHub-Delivery` into `webhook_delivery (id PRIMARY KEY, received_at)`. A duplicate, including a redelivery, is a no-op. Rows older than 7 days are purged. |
| Tenancy | The consumer runs with the Doctrine tenant filter off. Only `TenantFilter::suspend()` turns the filter off, in four places: the sign-in and token lookup, this webhook router, the projection rebuild, and the tenant purge. See [architecture section 4](../architecture.md). The consumer finds the `Repository` or `GitHubInstallation` of every tenant by GitHub ID. Each job that it queues carries its `tenantId`. The job handler sets the fail-closed tenant context in the application before it reads or writes tenant data. |

Events. Subscribe to `push` and `repository` in the App settings. The `installation` and `installation_repositories` events should arrive without a subscription. This is not verified: see the risks in [architecture.md](../architecture.md). Sign-in compares the installations anyway.

| `X-GitHub-Event` | Action | What the server does |
|---|---|---|
| `ping` | none | No-op |
| `push` | any, for branches and tags | For each tenant repository with `github.repositoryId = repository.id` and `access = active`, queue `FetchRepository(tenantId, repositoryId, reason: push)`. The fetch then does the steps of [section 2.2](#22-after-every-successful-fetch). |
| `installation` | `created` | If a user has `githubUserId = sender.id`, record the `GitHubInstallation` for that tenant and publish `candidates_changed`. Otherwise ignore it: the next sign-in records it. |
| `installation` | `deleted` | For each tenant with this installation: mark all its repositories `access = removed`, delete their clones with the pin refs and their index at once, publish `repository` for each, and delete the installation row. Reviews stay, by decision D22. |
| `installation` | `suspend`, `unsuspend` | Set or clear `suspendedAt`. While suspended, fetches fail with `sync.lastError = "GitHub App installation suspended"`, and operations that need a fetch get `repository_unavailable` with reason `fetch_failed`. |
| `installation` | `new_permissions_accepted` | No-op |
| `installation_repositories` | `added` | For installations on the own account of the user, where `account.type = User` and the GitHub user is the same, add the repositories to the candidate list of the tenant and publish `candidates_changed`. Organization installations refresh at the next sign-in, because user access cannot be checked without the user token. |
| `installation_repositories` | `removed` | For each connected repository in `repositories_removed`: `access = removed`, delete the clone and the index, remove it from the candidates, publish `repository` and `candidates_changed` |
| `repository` | `renamed`, `transferred` | Update `Repository.name` to `repository.full_name` and publish `repository`. The old name answers `not_found`. |
| `repository` | `deleted` | As `installation_repositories.removed` |
| `repository` | others, for example `privatized`, `publicized`, `edited` | Update `github.private` when it changed, otherwise no-op |
| `github_app_authorization` | `revoked` | Logged. The server never stores the user token, so there is nothing to revoke. |
| anything else | any | `github.ignored`: no-op |

## 2. Fetches and commit availability

The refspecs, the pin refs, and the meaning of "available" are in [README.md section 7](README.md#7-repository-sources-names-and-commit-availability).

### 2.1 Triggers

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
- **Local fetches:** the remote is `file://<realpath>`, where `<realpath>` is the real path of the directory. Every git command gets `-c safe.directory=<realpath>`. The source directory is mounted read-only and never written.
- **Failure:** the server sets `sync.lastError` and publishes a `repository` live update. For a synchronous trigger the caller gets 409 `repository_unavailable` with reason `fetch_failed`.

### 2.2 After every successful fetch

1. Set `lastFetchedAt`, clear `lastError`, and read `defaultBranch`, which is the remote HEAD, and `defaultBranchHead`.
2. Resolve pending links. The fetch has already fetched each pending linked SHA by ID into `refs/holistic-review/commits/<sha>`. For each pending `CommitLink` of the repository whose SHA is now present:
   - set it to `available`;
   - make sure that the pin ref `refs/holistic-review/commits/<sha>` exists;
   - write one `commit.found` event per review. The actor is `system`, and the payload is `{commitSha, requestIds}` for the requests linked in that review.
3. If `defaultBranchHead` changed, queue `RecomputeLocations(reviewId)` for each open review. The job recomputes the default-branch location of each request. When at least one `outdated` flag changed, it publishes `outdated` with the IDs of those requests. `outdated` means that the location at the default-branch head is `changed` or `orphaned`.
4. Publish `repository`, and one `events` update per review that got `commit.found`.

## 3. Live updates

| Item | Value |
|---|---|
| Hub | Built into FrankenPHP. The public URL `MERCURE_PUBLIC_URL` is `https://<host>/.well-known/mercure`, the same origin as the UI. The local development environment uses `http://localhost:8000/.well-known/mercure`. The hub URL is always set, because without it the bundle compiles `FrankenPhpHub` and console processes fail. |
| Publisher | `symfony/mercure-bundle` 0.5.0, `HubInterface::publish()`. Every update is `private: true`. |
| Subscriber authorization | The cookie that [A3](rest-api.md#a3-authorize-live-updates) sets. The JWT claim `mercure.subscribe` holds the two selectors of the tenant below. `exp` is now plus 3,600 s. Agents do not subscribe: they poll `get_updates`. |
| Base | `{base}` = `DEFAULT_URI` without a trailing slash, for example `https://hr.example` |
| Payloads | IDs only. The browser reloads the data through REST. Mercure carries no second copy of the data model. |

Topics:

| Topic | Selector in the JWT | Update types |
|---|---|---|
| `{base}/tenants/{tenantId}/repositories` | exact | `repository`, `repository_deleted`, `candidates_changed` |
| `{base}/tenants/{tenantId}/reviews/{reviewId}` | URI template `{base}/tenants/{tenantId}/reviews/{reviewId}`, which matches any review of the tenant | `events`, `outdated`, `index` |

Update types. [live-update.schema.json](schemas/live-update.schema.json) defines the payloads.

| `type` | Payload | Published | Fixture |
|---|---|---|---|
| `events` | `{type, reviewId, seq, requestIds}` | Once per committed write transaction that wrote events. `seq` is the number of the last event of the transaction. `requestIds` holds every request that its events concern, or `[]` when it wrote only review events. | [live-update.events.json](fixtures/live-update.events.json), [live-update.events-batch.json](fixtures/live-update.events-batch.json) |
| `outdated` | `{type, reviewId, requestIds}` | After a location recompute that changed at least one `outdated` flag. `requestIds` holds the requests whose flag changed. | [live-update.outdated.json](fixtures/live-update.outdated.json) |
| `index` | `{type, reviewId}` | While the index job runs, at most once per second per review, plus the final state | [live-update.index.json](fixtures/live-update.index.json) |
| `repository` | `{type, repositoryId}` | After each `sync.state` change, fetch, access change, and rename, and after [V13](rest-api.md#v13-delete-a-review) deletes a review of the repository | [live-update.repository.json](fixtures/live-update.repository.json) |
| `repository_deleted` | `{type, repositoryId}` | At [R6](rest-api.md#r6-delete-repository-data) | [live-update.repository-deleted.json](fixtures/live-update.repository-deleted.json) |
| `candidates_changed` | `{type}` | After the installation webhooks | [live-update.candidates-changed.json](fixtures/live-update.candidates-changed.json) |

Publishing rules:

- **When:** only after the write transaction commits. See [architecture.md](../architecture.md) section 4.
- **One transaction, one update:** a batch submission sends one `events` update for all its events. The update ID is `urn:holistic-review:{tenantId}:{seq}`.
- **Failures:** the server logs a publish failure and does not retry it. The write has already committed, and the UI catches up.
- **SSE event type:** the Mercure `type` is not set, so the browser receives every update through `EventSource.onmessage`. The JSON `type` field selects the handler. Clients ignore unknown types.

Client behaviour in the web UI:

1. **Subscribe.** Call [A3](rest-api.md#a3-authorize-live-updates), then open one `new EventSource(url, {withCredentials: true})`. `url` is `hubUrl` with one `topic` parameter for the repositories topic and one for the topic of the open review, each encoded with `encodeURIComponent`. Call A3 again before `expiresAt`. One small `useEventSource` hook holds the connection.
2. **Track the position.** Keep `lastSeq` per review. It comes from `events` updates, from `events[].seq` of command results, from `Review.lastEventSeq`, and from the `cursor` of the updates endpoint. Ignore an `events` update whose `seq` is not above `lastSeq`.
3. **Revalidate.** On an update, call `revalidate()` of `useRevalidator` from React Router 8.4.0 in Data Mode. It runs the loaders of the active routes again, and they read fresh data through REST. Only one revalidation runs at a time. Updates that arrive during a revalidation cause one more revalidation after it.
   - `events`, `outdated`, and `index`: when `reviewId` is the open review.
   - `repository`, `repository_deleted`, and `candidates_changed`: always.
4. **Catch up after a reconnect.** On every EventSource `open` after an error, and when the tab becomes visible again, call `GET /api/v1/reviews/{id}/updates?cursor=<lastSeq>&includeOwn=true` until `hasMore` is false. Set `lastSeq` to the last `cursor`, then revalidate once. The built-in hub keeps no history, so the UI does not rely on `Last-Event-ID` replay.
5. **Request detail.** The loader of the request detail view reads `GET /api/v1/requests/{id}`, so a revalidation also refreshes the thread and `allowedActions`. `allowedActions` depends on the caller, so it is never in a live payload.
