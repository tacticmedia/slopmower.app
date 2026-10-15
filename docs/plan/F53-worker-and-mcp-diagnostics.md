# F53 Worker and MCP diagnostics

Workflow, conventions, and status: [feature track](features.md). Two known issues from F51 and F52: a server error inside an MCP tool never reaches Sentry, and the idle worker logs one line per second and stops at its memory limit (candidates "MCP tool errors in Sentry" and "Idle worker log and memory").

## Goal

- A server error inside an MCP tool sends a Sentry event with the F51 data rules.
- The idle worker writes no log line per poll, and its memory stays flat while it is idle.

## Facts

Verified in the code on 2026-10-15:

- `McpTools::run()` catches every Throwable of a tool, logs it on the channel `app`, and answers `internal_error`. The exception never reaches `kernel.exception`, so the Sentry error listener does not see it.
- The Sentry bundle is enabled in `prod` only. F51 filters events in `App\Http\SentryClientErrorFilter` and keeps an event of a server error.
- `Symfony\Component\Messenger\Bridge\Doctrine\EventListener\PostgreSqlNotifyOnIdleListener::onWorkerRunning()` logs "Worker waiting for PostgreSQL LISTEN/NOTIFY wake-up." at `debug` on each idle poll, about once a second, on the channel `app`.
- The dev worker (`compose.yaml`, `--memory-limit=96M`) stopped at the limit 18 times in the main `dev.log` by 2026-10-15, about every 11 to 30 minutes while idle. Compose starts it again. The cause is not known.

## Decisions

- **MCP errors.** The catch in `McpTools::run()` also captures the exception through the Sentry hub when the hub exists. Outside `prod` there is no hub and nothing changes. The capture uses the F51 rules: no request body, no frame arguments.
- **Idle log line.** The listener logs on a channel of its own, which no `prod` or `dev` handler writes. The implementer checks how the framework wires the listener's logger and picks the smallest correct change, for example a `monolog.logger` tag through a service decoration or a `monolog.channels` entry.
- **Idle memory.** The implementer measures the growth of an idle worker, finds the cause, and fixes it. If the cause is in a vendor package, the fix is the smallest change in this application, and the report names the upstream issue.

## Depends on

F51 (the filter and the data rules).

## In scope

- `McpTools::run()` and its test.
- The logger channel of the idle listener in `config/`.
- The cause and the fix of the idle memory growth.
- Docs: `docs/deployment.md` "Error reporting" (MCP tool errors now send an event), D67 if it names the MCP gap, and the two candidates in [features.md](features.md), which F53 closes.

## Out of scope

- A Sentry Monolog handler.
- The memory of a worker that handles messages.

## Acceptance tests

- **F**: a server error inside an MCP tool reaches the Sentry hub when one exists, and the MCP answer is `internal_error` as before.
- **F**: the idle listener logs on its own channel, and no `dev` or `prod` handler takes that channel.
- **F** or measurement in the report: the memory of an idle worker does not grow over a number of idle polls that matches at least 30 minutes of the old rate.

## Verification

- `make check` is green.
- `make up`: after 30 minutes of idle time, the worker has not stopped at its memory limit, and the dev log has no "Worker waiting" lines.
