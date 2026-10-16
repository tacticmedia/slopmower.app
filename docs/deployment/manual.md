# Manual deployment

A host install runs the PHP processes behind nginx, the [Messenger worker](#messenger-worker) under a process manager, the [`symbols` service](#symbols-service), and the [Mercure hub](#mercure-hub) behind nginx. [Deployment](README.md) describes the environment variables of the application. [Dockerised deployment](dockerised.md) runs the same parts in containers.

## Messenger worker

Run at least one worker that consumes both transports, under a process manager that starts it again when it exits:

```bash
bin/console messenger:consume async scheduler_default --time-limit=3600
```

- `async` runs the background jobs: clones and fetches, the symbol index, the GitHub webhook jobs, and deletions.
- `scheduler_default` is the schedule `App\Schedule`. Each day at 03:00 UTC, it deletes the expired rows of `rate_limiter.cache` and the messages of the queue `failed` that are older than 30 days. The purge deletes in batches of 100, and logs one record at `error` per batch with the count, and the ID and the message class of each deleted message. Both jobs are idempotent, so several workers can consume the schedule.
- The schedule keeps its last run in the cache pool `cache.app`, under `<APP_SHARE_DIR>/<environment>/pools/app`. A worker that starts at or after 03:00 UTC after a missed run runs it once. A worker that starts before 03:00 UTC waits for 03:00. Without that directory, a missed run waits for the next day.
- The worker reads `scheduler_default` only when `async` is empty, so a backlog in `async` makes the daily run late.
- A failed job is not retried, because `scheduler_default` has no retry strategy and no failure transport. Messenger logs the failure at `critical`, and the job runs again the next day.

## Symbols service

The service extracts the symbols of every [symbol language](../architecture.md#symbol-languages) but PHP and Twig (D56). The web server and the Messenger worker both call it through the scoped client `symbols.client`. When the service is down, the index job stores no symbols for these languages, and a request that needs their symbols answers 500. PHP and Twig files do not use the service.

### Requirements

| Item | Value |
|---|---|
| Node.js | 24.12 or later, from `engines` in `symbols/package.json`. Node runs `symbols/src/server.ts` with type stripping, so there is no build step. |
| Files | `symbols/package.json`, `symbols/package-lock.json`, `symbols/src/`, and `symbols/queries/`, from the release of the PHP code. |
| Build tools | None. |
| Memory | About 150 MB resident after start, with the 14 languages loaded. |
| Disk | About 260 MB for `symbols/node_modules`. |
| Address | `127.0.0.1:3000` by default. `SYMBOLS_HOST` and `SYMBOLS_PORT` change it, see [Run](#run). |

### Install

In the `symbols/` directory of the release:

```sh
npm ci --ignore-scripts --no-audit --no-fund
```

`--ignore-scripts` is necessary. The install scripts of the grammar packages compile Node bindings, and they fail without Python and a C compiler. The service loads only the `.wasm` grammars, so it does not need the bindings. `npm ci` installs the exact versions of `package-lock.json`. Run it for each release.

### Run

```sh
node symbols/src/server.ts
```

The service finds its grammars and queries relative to `src/server.ts`, so the working directory has no effect. `npm start` in `symbols/` adds an npm parent process. Run `node` directly, so that the process manager supervises and signals the service process itself.

| Variable | Default | Value |
|---|---|---|
| `SYMBOLS_HOST` | `127.0.0.1` | The address to listen on. `0.0.0.0` or `::` listens on all interfaces. |
| `SYMBOLS_PORT` | `3000` | The TCP port. |

An empty value means the default. The `symbols` image sets `SYMBOLS_HOST=0.0.0.0`.

Configure the process manager, for example a systemd unit, as follows:

- Run the service as an unprivileged user. The user needs read access to `symbols/` only. The service writes no files.
- Restart the service when it stops. It stops with exit code 1 when its port is in use (`EADDRINUSE`), when `SYMBOLS_PORT` is no valid port (`ERR_SOCKET_BAD_PORT`), or when a grammar does not load.
- Stop it with `SIGTERM` or `SIGINT`. The server then accepts no new connections, and it stops when the open requests end.
- Start the web server and the worker after the service is healthy. The service loads all grammars before it listens, so the first 200 from `/healthz` means that it is ready. Compose waits for the same check.
- Collect stdout and stderr. The service writes one line at start with its address and its languages, and a stack trace for each failed extraction.

Check the service:

```sh
curl -fsS http://127.0.0.1:3000/healthz
curl -fsS -H 'Content-Type: application/octet-stream' --data-binary @symbols/src/server.ts 'http://127.0.0.1:3000/v1/symbols?language=typescript'
```

The first command gives `{"status":"ok"}`. The second gives `"parseError":false` and a list of `symbols`. [Symbol languages](../architecture.md#symbol-languages) gives the full contract.

### Network access

The service has no authentication. With the default `SYMBOLS_HOST`, only processes on the same host reach it. When the web server or the worker runs on another host, set `SYMBOLS_HOST` to an address that they reach, and block the port for all other clients with a firewall or a security group.

[`SYMBOLS_URL`](README.md#symbols-service) connects the application to the service.

### Deploy a new release

1. Install and restart the service before the web server and the worker. `TreeSitterSymbolExtractor::LANGUAGES` must list only languages that the service knows. The service answers 422 to an unknown language, and the request that needs the symbols answers 500.
2. A changed grammar or query file does not change the stored symbols. `app:index:rebuild <tenantId> <reviewId> --reparse` deletes the parsed blobs and symbols of the repository of the review, and queues the index job of the review. A review indexed before the release that added its language needs `app:index:rebuild <tenantId> <reviewId>`. See "Index version per language" in the [candidates](../plan/features.md#candidates).

## Mercure hub

One hub serves `https://beta.slopmower.app` and `https://slopmower.app`. It listens on `127.0.0.1:8000`. nginx forwards `/.well-known/mercure` of each site to it, so browsers reach the hub on the origin of the UI. [The Mercure variables](README.md#mercure-hub) connect the application to it.

### Hub configuration

Run the `mercure` binary of a Mercure 1.x release with this Caddyfile:

```caddyfile
{
	admin off
}

:8000 {
	bind 127.0.0.1

	mercure {
		protocol_version_compatibility 8
		publisher_jwt {env.MERCURE_JWT_SECRET}
		subscriber_jwt {env.MERCURE_JWT_SECRET}
		cors_origins https://beta.slopmower.app https://slopmower.app
	}

	respond "Not Found" 404
}
```

```sh
MERCURE_JWT_SECRET=<secret> mercure run --config Caddyfile --adapter caddyfile
```

- `MERCURE_JWT_SECRET` has the value of the application.
- `admin off` disables the Caddy admin API. Each Caddy process listens on `localhost:2019` by default, so a second one on the host does not start. Restart the hub to load a changed Caddyfile.
- `:8000` with `bind 127.0.0.1` accepts every `Host` on the loopback interface only. Do not use the address `http://localhost:8000`: Caddy then serves only requests with `Host: localhost`, but nginx forwards the public host. Caddy also listens on all interfaces.
- `symfony/mercure-bundle` 0.5.0 uses protocol 0.x: the `mercure` claim without `iss` or `aud`, and the cookie `mercureAuthorization`. A 1.x hub accepts these only with `protocol_version_compatibility 8`, and it refuses to start with `publisher_jwt` or `subscriber_jwt` without it. Official binaries and the `dunglas/mercure` image have the build tags that this mode needs. See "Mercure protocol 1.0" in the [candidates](../plan/features.md#candidates).
- `cors_origins` allows a page of one site to subscribe through the hub URL of the other site. The URL of the own site needs no CORS.
- The hub keeps the update history in `mercure.db` in the Caddy data directory. The user of the hub process needs write access to it. Set `HOME` for the hub process: without it, Caddy uses `./caddy` in the working directory.
- Beta and production share `MERCURE_JWT_SECRET`, so the beta secret can sign tokens for production topics. The topics do not collide, because each site starts them with its own `DEFAULT_URI`.

### nginx

Add the `location` to the `server` block of each site:

```nginx
upstream mercure {
    server 127.0.0.1:8000;
}

server {
    server_name slopmower.app;
    # TLS and the application locations

    location ^~ /.well-known/mercure {
        proxy_pass http://mercure;
        proxy_http_version 1.1;
        proxy_set_header Connection "";

        proxy_buffering off;
        proxy_cache off;
        proxy_read_timeout 24h;

        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Host  $host;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

- `proxy_buffering off`: without it, nginx sends events in batches.
- `proxy_read_timeout 24h`: the default of 60 s closes each idle event stream. The hub sends a heartbeat every 40 s.
- `proxy_http_version 1.1` and `Connection ""` configure the connection to the hub. HTTP/2 to the browser is a separate setting.
- `^~` stops the check of regex locations. A rule such as `location ~ /\. { deny all; }` would otherwise answer 403.
- `proxy_pass` has no URI, so the path and the `topic` query reach the hub unchanged.
