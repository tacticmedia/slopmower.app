# Deployment outside Docker Compose

Docker Compose runs PostgreSQL and the `symbols` service in development. A deployment without Compose must connect the application to its database, run the [Messenger worker](#messenger-worker), install, run, and connect the `symbols` service, and run the [Mercure hub](#mercure-hub) behind nginx.

[`.env.prod.dist`](../.env.prod.dist) lists every variable whose `.env` default is wrong in production, with example values. Symfony never loads it.

## Database connection

Set these variables for every PHP process: the web server, each Messenger worker, and each console command (D63). The Doctrine connection, the Messenger queue, and the locks all use them.

| Variable | Value |
|---|---|
| `DATABASE_HOST` | Host name of PostgreSQL, for example the RDS endpoint |
| `DATABASE_PORT` | TCP port, usually `5432` |
| `DATABASE_NAME` | Database name |
| `DATABASE_USER` | Database user. IAM authentication replaces it. |
| `DATABASE_PASSWORD` | The raw password, not URL-encoded. IAM authentication replaces it. |
| `DATABASE_SERVER_VERSION` | PostgreSQL major version, for example `18` |
| `DATABASE_SSLMODE` | A libpq `sslmode`, for example `verify-full`. Empty omits it: libpq uses `prefer`, and IAM authentication uses `require`. |

- In a `.env` file, put the password in single quotes. Dotenv keeps `$` and `\` literal there, and an RDS password cannot contain `'`.
- `sslmode=verify-full` needs the RDS certificate bundle. Set `PGSSLROOTCERT` to its path in the environment of the PHP process. libpq reads it.
- The accepted credential lives unencrypted in the cache pool `rds_credentials.cache`, under `<APP_SHARE_DIR>/<environment>/pools/app`: an IAM token for 10 minutes, the master password until the next rotation (D58). `APP_SHARE_DIR` defaults to `var/share`. Keep that directory on local storage, readable only by the PHP user, and shared by the web server and every worker of the host.
- In the PostgreSQL server configuration, on RDS the DB parameter group, set `log_error_verbosity = terse` and `log_min_error_statement = panic`. With the defaults, the server log writes the `DETAIL` line of a failed statement, which holds the row values, and the statement with its literals. An exported server log would then hold code and agent text.
- On Amazon RDS, `AWS_REGION` with `RDS_IAM_USERNAME` selects IAM token authentication, and `AWS_REGION` with `RDS_SECRET_ARN` reads the master password from Secrets Manager after a rotation (D58). The [bundle documentation](https://github.com/tacticmedia/rds-auth-bundle/blob/main/docs/configuration.md) lists the AWS prerequisites.

## Usage and rate limits

The [usage limits](specs/README.md#usage-limits) are the values of the [plan of the tenant](specs/webhooks-and-live-updates.md#marketplace-plans). A tenant without a GitHub Marketplace plan has the free plan. The [rate limits](specs/README.md#rate-limits) are values of the instance. The `.env` defaults apply when a variable is not set.

| Variable | Default | Value |
|---|---|---|
| `APP_PLAN_FREE_REPOSITORIES` | `25` | Connected repositories per tenant. `0` or empty: off. |
| `APP_PLAN_FREE_OPEN_REVIEWS` | `20` | Open reviews per tenant. `0` or empty: off. |
| `APP_PLAN_FREE_REQUESTS_PER_REVIEW` | `1000` | Requests per review. `0` or empty: off. |
| `APP_PLAN_FREE_REPOSITORY_SIZE_MIB` | `2048` | Size of one clone in MiB. `0` or empty: off. |
| `APP_PLAN_FREE_TENANT_STORAGE_MIB` | `10240` | Size of all clones of a tenant in MiB. `0` or empty: off. |
| `APP_PLAN_FREE_MARKETPLACE_PLAN_ID` | empty | The Marketplace plan ID of the free plan of the listing. Empty: the listing has no free plan. |
| `APP_PAID_PLANS` | empty | The paid plans, a JSON object by plan key. Empty: no paid plans. See below. |
| `GITHUB_MARKETPLACE_URL` | empty | The listing, for example `https://github.com/marketplace/slopmower`, which Settings links. Empty: no link. |
| `APP_RATE_LIMIT_API_TOKEN_PER_MINUTE` | `300` | Calls per API token, at least 1 |
| `APP_RATE_LIMIT_API_USER_PER_MINUTE` | `1200` | Calls per user, at least 1 |
| `APP_RATE_LIMIT_MCP_HTTP_PER_MINUTE` | `600` | HTTP requests to `/_mcp` per API token, at least 1 |
| `APP_RATE_LIMIT_SYNC_FETCH_PER_MINUTE` | `10` | Synchronous fetches per repository, at least 1 |
| `APP_RATE_LIMIT_REVIEW_START_PER_HOUR` | `30` | Review starts per tenant, at least 1 |

- Each paid plan in `APP_PAID_PLANS` has the keys `name`, `marketplace_plan_id`, `rank` of at least 1, and the five limits of the free plan in lower case without the prefix, with `0` for off. The plan of a tenant is the plan with the highest rank among its GitHub accounts. A wrong entry fails every request that reads a plan, and the error names the entry.

  ```sh
  APP_PAID_PLANS='{"pro": {"name": "Pro", "marketplace_plan_id": 1313, "rank": 1, "repositories": 100, "open_reviews": 100, "requests_per_review": 5000, "repository_size_mib": 4096, "tenant_storage_mib": 51200}}'
  ```

- The plan IDs come from `GET /marketplace_listing/plans` with the App JWT. A Marketplace plan that `APP_PAID_PLANS` does not map counts as the free plan and logs an error, so add a new plan of the listing here before it is published.
- The Marketplace listing sends its own webhook. Set it to `https://<host>/webhook/github`, content type JSON, the secret `GITHUB_APP_WEBHOOK_SECRET`, and Active.
- The rate limiter state is in the table `cache_items` of the database, so every web server and worker shares it. The daily job of the [Messenger worker](#messenger-worker) deletes the expired rows.
- A clone gets its size at its next clone or fetch. Until then it counts as 0 bytes.

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

## Error reporting

In `prod`, the application sends server errors to Sentry (D67). Set `SENTRY_DSN` to the DSN of the Sentry project for every PHP process. Empty switches Sentry off. `SENTRY_RELEASE` names the release of each event, for example the commit SHA.

- Each failure of a request, a Messenger job, or a console command sends an event, also a failed job that Messenger retries. A request that ends below 500 sends none, for example a 404, a 409 `limit_exceeded`, or a 429 `rate_limited`.
- A server error inside an MCP tool sends an event with the same rules. The tool answers `internal_error`. A tool error with another code, for example `not_found`, sends none.
- An event holds the exception with its message and stack trace, the 5 source lines around each frame of Slopmower and its packages, the server name, the environment, the release, and the installed packages. For a request, it also holds the method, the URL, the headers, and the route name once a controller takes the request. For a job: the message class, the receiver, and the bus. For a command: the command line.
- An event holds no request body, no frame arguments, no cookies, no IP address, and no user. The values of the query parameters `q`, `name`, `confirm`, and `code`, of the forwarded IP headers, and of the credential headers, for example `Authorization`, are `[Filtered]`.
- No tracing, no Monolog handler, and no logs go to Sentry.
- In the Sentry project, keep the data scrubbing of the Security & Privacy settings on, and turn on the setting that stops storing IP addresses, as a second layer.

## Symbols service

The service extracts the symbols of every [symbol language](architecture.md#symbol-languages) but PHP and Twig (D56). The web server and the Messenger worker both call it through the scoped client `symbols.client`. When the service is down, the index job stores no symbols for these languages, and a request that needs their symbols answers 500. PHP and Twig files do not use the service.

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

The first command gives `{"status":"ok"}`. The second gives `"parseError":false` and a list of `symbols`. [Symbol languages](architecture.md#symbol-languages) gives the full contract.

### Network access

The service has no authentication. With the default `SYMBOLS_HOST`, only processes on the same host reach it. When the web server or the worker runs on another host, set `SYMBOLS_HOST` to an address that they reach, and block the port for all other clients with a firewall or a security group.

### Configure the application

Set `SYMBOLS_URL` for every PHP process: the web server and each Messenger worker.

| Where the service runs | `SYMBOLS_URL` |
|---|---|
| On the host of the PHP process | `http://127.0.0.1:<SYMBOLS_PORT>` |
| On another host | `http://<host>:<SYMBOLS_PORT>` |

- The `.env` default `http://symbols:3000` resolves only in the Compose network.
- Give the scheme, the host, and the port only. The client requests the absolute path `/v1/symbols`, so it drops a path in `SYMBOLS_URL`. With `http://proxy/symbols/`, the request goes to `http://proxy/v1/symbols`. A reverse proxy must serve the service at its root path.

From the host of each PHP process, run the `/healthz` check against the `SYMBOLS_URL` value.

### Deploy a new release

1. Install and restart the service before the web server and the worker. `TreeSitterSymbolExtractor::LANGUAGES` must list only languages that the service knows. The service answers 422 to an unknown language, and the request that needs the symbols answers 500.
2. A changed grammar or query file does not change the stored symbols. `app:index:rebuild <tenantId> <reviewId> --reparse` deletes the parsed blobs and symbols of the repository of the review, and queues the index job of the review. A review indexed before the release that added its language needs `app:index:rebuild <tenantId> <reviewId>`. See "Index version per language" in the [candidates](plan/features.md#candidates).

## Mercure hub

One hub serves `https://beta.slopmower.app` and `https://slopmower.app`. It listens on `127.0.0.1:8000`. nginx forwards `/.well-known/mercure` of each site to it, so browsers reach the hub on the origin of the UI.

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
- `symfony/mercure-bundle` 0.5.0 uses protocol 0.x: the `mercure` claim without `iss` or `aud`, and the cookie `mercureAuthorization`. A 1.x hub accepts these only with `protocol_version_compatibility 8`, and it refuses to start with `publisher_jwt` or `subscriber_jwt` without it. Official binaries and the `dunglas/mercure` image have the build tags that this mode needs. See "Mercure protocol 1.0" in the [candidates](plan/features.md#candidates).
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

### Configure the application

| Variable | Value |
|---|---|
| `MERCURE_URL` | `http://127.0.0.1:8000/.well-known/mercure` on the host of the hub. The PHP processes publish to the hub directly. On another host, use the `MERCURE_PUBLIC_URL` value. |
| `MERCURE_PUBLIC_URL` | `https://<site>/.well-known/mercure`, for example `https://beta.slopmower.app/.well-known/mercure` |
| `MERCURE_JWT_SECRET` | The value of the hub |

Check each site:

```sh
curl -sS -o /dev/null -w '%{http_code}\n' 'https://slopmower.app/.well-known/mercure?topic=x'
```

The command gives `401`, because the request has no token.
