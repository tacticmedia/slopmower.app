# M34 GitHub webhooks

Workflow, conventions, and status: [README](README.md).

## Goal

`push` and installation webhooks keep the clones current and remove code data when access ends, decision D22. The contract is docs/specs/webhooks-and-live-updates.md sections 1 and 2.

## Depends on

M33, M16, M24.

## In scope

- `composer require symfony/webhook symfony/remote-event`, versions 8.1.7 and 8.1.0.
- The endpoint `POST /webhook/github`. The recipe route in `config/routes/webhook.yaml` has the prefix `/webhook`. It is the GitHub App's webhook URL.
- Wiring: `framework.webhook.routing.github` with `service: App\Webhook\GitHubRequestParser` and `secret: '%env(GITHUB_APP_WEBHOOK_SECRET)%'`. The consumer has `#[AsRemoteEventConsumer('github')]`. `Symfony\Component\Webhook\Messenger\ConsumeRemoteEventMessage` is routed to `async`.
- `GitHubRequestParser`:
  - the request matcher needs POST, `Content-Type: application/json`, and the headers `X-GitHub-Event` and `X-GitHub-Delivery`. Otherwise Symfony answers 406. The matcher does not check `X-Hub-Signature-256`, so a missing signature gets the 403 of the signature check;
  - `X-Hub-Signature-256` must be `sha256=` plus the lower-case hex of `hash_hmac('sha256', $request->getContent(), $secret)`, compared with `hash_equals()` on the raw body before JSON decoding. A missing or wrong signature throws `RejectWebhookException(403, 'Invalid signature.')`. A SHA-1 `X-Hub-Signature` header is ignored; an empty secret refuses every delivery with 403, because anyone can compute an HMAC with an empty key;
  - unparsable JSON gives 406;
  - every verified delivery returns a `RemoteEvent`: name `github.<X-GitHub-Event>`, ID from `X-GitHub-Delivery`, payload the decoded body. Events that the server does not handle get the name `github.ignored`. Returning `null` would make Symfony answer 406;
  - the response is 202 with an empty body. The work runs in the worker.
- Duplicates: `webhook_delivery` holds the delivery IDs, with `id` as primary key and `received_at`. The consumer skips a delivery whose ID is in the table. It inserts the ID after its work, with `ON CONFLICT DO NOTHING`, so a failed run leaves no row, and the retry does the work. Two deliveries of one ID can run at the same time, so every handler below is idempotent. After each insert, the consumer deletes rows older than 7 days. `WebhookDelivery` is not tenant owned: add it to the exception list of `EntityTenancyConventionTest`.
- Tenancy: the consumer runs inside `TenantFilter::suspend()`. `ConsumeRemoteEventMessage` has no `TenantStamp`, so M34 adds it to the tenantless list of the M09 middleware. The consumer finds every tenant's `Repository` or `GitHubInstallation` by GitHub ID. Each resulting job carries its tenant in a `TenantStamp`, and the application sets the tenant context from it.
- Event handling, as the table in docs/specs/webhooks-and-live-updates.md section 1 says:
  - `ping`: no-op;
  - `push`: for each tenant repository with this GitHub ID and `access = active`, request an asynchronous fetch through the M09 trigger. After the fetch, the steps of section 2.2 run: resolve pending links with `commit.found` and pins, queue `RecomputeLocations` when the default-branch head changed, publish `repository` and `events`;
  - `installation` `created`: record the installation for the tenant whose user has `githubUserId = sender.id`, and publish `candidates_changed`. Otherwise ignore it;
  - `installation` `deleted`: for each tenant with this installation, mark its repositories `access = removed`, delete their clones and index at once, publish `repository` for each, delete the candidates of the installation, publish `candidates_changed`, and delete the installation row. Reviews stay;
  - `installation` `suspend` and `unsuspend`: set or clear `suspendedAt`. While suspended, fetches fail and operations that need a fetch get `repository_unavailable` with reason `fetch_failed`;
  - `installation` `new_permissions_accepted`: no-op;
  - `installation_repositories` `added`: for installations on the user's own account, add the repositories to the candidates and publish `candidates_changed`;
  - `installation_repositories` `removed`, and `repository` `deleted`: `access = removed`, delete the clone and index, remove the candidate, publish `repository` and `candidates_changed`;
  - `repository` `renamed` and `transferred`: set `Repository.name` to `repository.full_name` and publish `repository`. The old name answers `not_found`;
  - other `repository` actions: update `github.private` when it changed;
  - `github_app_authorization` `revoked`: logged only;
  - anything else: `github.ignored`, no-op.
- The live update `{type: "candidates_changed"}` on the repositories topic.

## Out of scope

- Other GitHub events.

## Files (representative)

- `src/Webhook/GitHubRequestParser.php`, `src/Webhook/GitHubWebhookConsumer.php`, `src/Webhook/Handler/*.php`.
- `src/Entity/WebhookDelivery.php`, `migrations/Version*.php`.
- `config/packages/webhook.yaml` or the `framework.webhook` section, `config/routes/webhook.yaml` from the recipe, `config/packages/messenger.yaml`.
- `tests/Fixtures/github/webhooks/*.json`, `tests/Functional/Webhook/*Test.php`.

## Tasks (ordered)

1. Install the packages. Check the route prefix that the recipe wrote.
2. Write the parser and the wiring.
3. Write the delivery table and run `make migration`.
4. Write the consumer and the event handlers.
5. Record webhook payloads as fixtures and write the tests.

## Acceptance tests

All are functional tests.

- **F `WebhookSignatureTest`** proves the HMAC check.
  - "A valid signature gives 202"
  - "A missing or wrong signature gives 403"
  - "A request with only the SHA-1 header gives 403"
  - "With an empty secret, every delivery gets 403"
- **F `WebhookParsingTest`** proves the parser rules.
  - "A request without the GitHub headers gives 406"
  - "Unparsable JSON with a valid signature gives 406"
  - "An event that the server does not handle gives 202 as github.ignored"
- **F `DuplicateDeliveryTest`** proves deduplication.
  - "The same delivery ID runs once"
  - "A consumer run that fails leaves no delivery row"
- **F `PushWebhookTest`** proves push handling across tenants.
  - "Two tenants with the same GitHub repository each get one fetch"
  - "A pending link resolves with commit.found and a pin"
- **F `AccessRemovedWebhookTest`** proves decision D22.
  - "installation deleted marks the repositories removed, deletes clones and index, and keeps the reviews"
  - "installation deleted removes the candidates of the installation and publishes candidates_changed"
  - "installation_repositories removed does the same for the listed repositories"
- **F `InstallationWebhookTest`** proves the other installation events.
  - "installation created records the installation for the matching user and publishes candidates_changed"
  - "suspend makes fetches fail with repository_unavailable"
  - "repository renamed updates the name"
- **F `WebhookTenantFilterTest`** proves tenant handling.
  - "Each job from the consumer runs under its own tenant"
  - "The consumer message passes the tenant middleware without a stamp"

## Verification commands

```sh
make migration
make db-test
make check
```

## Done

GitHub repositories stay current without polling, and access removal deletes the code data at once.
