# F37 Symbols service address

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-08 for the host and the port of the `symbols` service from the environment. This feature implements the candidate "Symbols service address" from [deployment](../deployment/manual.md#symbols-service).

## Goal

- The environment sets the listen address and the port of the `symbols` service.
- Outside a container, the service listens on the loopback interface by default, so a host without a firewall rule does not expose it.

## Facts

- `symbols/src/server.ts` calls `server.listen(3000)`. Without a host, Node listens on `::`, and on most systems also on `0.0.0.0`.
- The service has no authentication.
- In Compose, `php` and `worker` call `http://symbols:3000` over the compose network. The compose healthcheck calls `http://localhost:3000/healthz` inside the container.

## Decisions

- The variables are `SYMBOLS_HOST` and `SYMBOLS_PORT`. The prefix keeps them apart from a generic `HOST` or `PORT` in an environment that the service shares with other processes.
- The defaults are `127.0.0.1` and `3000`. An empty value means the default.
- The `symbols` stage of the `Dockerfile` sets `ENV SYMBOLS_HOST=0.0.0.0`, so every container of the image is reachable on its network. `compose.yaml` does not change.
- The service passes `SYMBOLS_PORT` to Node unchanged. Node refuses an invalid port with `ERR_SOCKET_BAD_PORT`, and the service stops with exit code 1. There is no fallback to `3000`.
- The start line names the bound address from `server.address()`, for example `Listening on 127.0.0.1:3000: javascript, ...`. So `SYMBOLS_PORT=0` reports the free port that the system picked.
- `SYMBOLS_URL` and the PHP side do not change.

## Depends on

F27 (merged).

## In scope

`symbols/src/server.ts`, `symbols/src/server.test.ts`, `Dockerfile`, `docs/deployment.md`, `docs/plan/README.md` (environment table), `docs/plan/features.md`.

## Out of scope

- Authentication of the service.
- A Unix socket instead of TCP.
- A healthcheck that reads `SYMBOLS_PORT`: Compose does not set it.

## Acceptance tests

- **V `server.test.ts`:** without `SYMBOLS_HOST`, the service listens on `127.0.0.1` and answers `GET /healthz`.
- **V `server.test.ts`:** with `SYMBOLS_HOST=0.0.0.0`, the service listens on `0.0.0.0` and answers `GET /healthz` on `127.0.0.1`.
- **V `server.test.ts`:** an invalid `SYMBOLS_PORT` stops the service with exit code 1.
- **F, existing:** the PHP tests that call the `symbols` service of the compose stack pass, so the image listens on the compose network.

## Verification

- `make up`: the `symbols` container is healthy.
- `node symbols/src/server.ts` on the host listens on `127.0.0.1:3000` only.
- `make check` is green, with `make act`, because the `Dockerfile` is in `ACT_INPUTS`.
