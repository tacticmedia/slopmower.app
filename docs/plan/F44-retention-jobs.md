# F44 Retention jobs

Workflow, conventions, and status: [feature track](features.md). The review of the F43 privacy policy on 2026-10-13 found data that nothing removes, and log content that the policy and [architecture section 12](../architecture.md#12-risks) ("no code in logs") exclude. F43 states the retention that F44 makes true.

## Goal

- Data with a retention period is removed on time, without an operator.
- Production logs hold no code and no text of agent calls.

## Facts

Verified in the code on 2026-10-13:

- `rate_limiter.cache` is a `cache.adapter.doctrine_dbal` pool (F39). `DoctrineDbalAdapter` deletes an expired row only when its key is read again. Each row keys a limiter by a token, user, tenant, or repository ID. The tenant purge does not touch the pool.
- A failed Messenger message moves to the queue `failed` after its retries (`config/packages/messenger.yaml`) and stays there. GitHub webhook messages hold the payload, with names and e-mail addresses of senders, pushers, and commit authors.
- `webhook_delivery` rows older than 7 days are deleted when a new delivery is recorded (`WebhookDeliverySql`).
- mcp/sdk logs each MCP request at `INFO` on the channel `mcp`, with the full JSON-RPC body. In `prod` the `main` handler is `fingers_crossed` over every channel except `deprecation`, so an error writes the buffered request bodies to `php://stderr`.
- No scheduler exists. The worker consumes only `async`.

## Decisions

- **Scheduler.** `composer require symfony/scheduler`. One schedule of the application, consumed by the existing worker: the compose `worker` command consumes `async` and the schedule transport. `docs/deployment.md` names both.
- **Daily jobs:**
  - prune `rate_limiter.cache`, which deletes the expired rows;
  - delete the messages of the queue `failed` that are older than 30 days.
- **Logs.** In `prod`, the channel `mcp` stays out of the `main` buffer, and a handler of its own writes only records of level `error` and above. A record of that handler holds no request body. If the SDK puts the body into its error records, the channel `mcp` is not logged in `prod`, and the report says what is lost.
- **Retention to state in F43:** rate limit rows at most one day after they expire; failed GitHub event messages 30 days; webhook delivery IDs 7 days.

## Depends on

F39 (the pool).

## In scope

- The Scheduler package, the schedule, the two jobs, and the worker command in `compose.yaml` and `docs/deployment.md`.
- `config/packages/monolog.yaml` for `prod`.
- Docs: architecture section 9 (the worker row), section 12 (the risk "We host other developers' source code": no code and no agent text in logs), and `docs/developer-guide.md` where it lists jobs.

## Out of scope

- The size of the dev log (candidate "Dev log size").
- A purge of rate limit rows by tenant. They expire within the interval of their limiter.

## Acceptance tests

- **F** `RetentionJobsTest`: the prune job deletes an expired row of `rate_limiter.cache` and keeps a live one; the failed-message job deletes a message of the queue `failed` older than 30 days and keeps a newer one and every message of `async`.
- **F**: the schedule holds both jobs once a day.
- **F** `ProdLogChannelsTest`, or `debug:config monolog` in the report if a test cannot boot `prod`: the channel `mcp` is not in the `main` handler of `prod`.

## Verification

- `make check` is green, with `make act`, because `compose.yaml`, `composer.json`, and `composer.lock` change.
- `make up`: the worker logs that it consumes the schedule.
