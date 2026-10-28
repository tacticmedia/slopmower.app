# Deployment

Docker Compose runs PostgreSQL and the `symbols` service in development only. A production deployment connects the application to its database, runs the Messenger worker and the `symbols` service, and runs a Mercure hub behind nginx. There are two methods:

- [Manual deployment](manual.md): PHP processes on the host, the worker under a process manager.
- [Dockerised deployment](dockerised.md): the image `frankenphp_prod` for the web server, the workers, and the release step, and the image `symbols`.

[`.env.prod.dist`](../../.env.prod.dist) lists every variable whose `.env` default is wrong in production, with example values. Symfony never loads it. This page describes the variables that both methods share.

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
- The accepted credential lives unencrypted in the cache pool `rds_credentials.cache`, under `<APP_SHARE_DIR>/<environment>/pools/app`: an IAM token for 10 minutes, the master password until the next rotation (D58). `APP_SHARE_DIR` defaults to `var/share`. Keep that directory on local storage, readable only by the PHP user, and shared by the web server and every worker of the host. The cache warmup compiles `APP_SHARE_DIR` into the container, so set it before the warmup.
- In the PostgreSQL server configuration, on RDS the DB parameter group, set `log_error_verbosity = terse` and `log_min_error_statement = panic`. With the defaults, the server log writes the `DETAIL` line of a failed statement, which holds the row values, and the statement with its literals. An exported server log would then hold code and agent text.
- On Amazon RDS, `AWS_REGION` with `RDS_IAM_USERNAME` selects IAM token authentication, and `AWS_REGION` with `RDS_SECRET_ARN` reads the master password from Secrets Manager after a rotation (D58). The [bundle documentation](https://github.com/tacticmedia/rds-auth-bundle/blob/main/docs/configuration.md) lists the AWS prerequisites.

## Usage and rate limits

The [usage limits](../specs/README.md#usage-limits) are the values of the [plan of the tenant](../specs/webhooks-and-live-updates.md#marketplace-plans). A tenant without a GitHub Marketplace plan has the free plan. The [rate limits](../specs/README.md#rate-limits) are values of the instance. The `.env` defaults apply when a variable is not set.

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
- The rate limiter state is in the table `cache_items` of the database, so every web server and worker shares it. The daily job of the [Messenger worker](manual.md#messenger-worker) deletes the expired rows.
- A clone gets its size at its next clone or fetch. Until then it counts as 0 bytes.

## Error reporting

In `prod`, the application sends server errors to Sentry (D67), and the SPA sends browser errors, traces, and session replays (D86). Set these for every PHP process:

- `SENTRY_DSN`: the DSN of the Sentry project for the server. Empty switches server reports off.
- `SENTRY_DSN_FRONTEND`: the DSN of the Sentry project for the browser. Empty switches browser reports off. It goes into the HTML at run time, so the front-end build does not change.
- `APP_RELEASE`: the release of each event of both, for example the commit SHA. The SPA footer shows it.
- `SENTRY_ENVIRONMENT`: the environment of each event of both, for example `production`. Empty gives `prod`.

Server reports:

- Each failure of a request, a Messenger job, or a console command sends an event, also a failed job that Messenger retries. A request that ends below 500 sends none, for example a 404, a 409 `limit_exceeded`, or a 429 `rate_limited`.
- A server error inside an MCP tool sends an event with the same rules. The tool answers `internal_error`. A tool error with another code, for example `not_found`, sends none.
- An event holds the exception with its message and stack trace, the 5 source lines around each frame of Slopmower and its packages, the server name, the environment, the release, and the installed packages. For a request, it also holds the method, the URL, the headers, and the route name once a controller takes the request. For a job: the message class, the receiver, and the bus. For a command: the command line.
- An event holds no request body, no frame arguments, no cookies, no IP address, and no user. The values of the query parameters `q`, `name`, `confirm`, and `code`, of the forwarded IP headers, and of the credential headers, for example `Authorization`, are `[Filtered]`.
- No tracing, no Monolog handler, and no logs go to Sentry.

Browser reports:

- An uncaught error, an error that a screen shows in place of its content, and an API answer of 500 or more send an event. An API answer below 500 sends none.
- An event holds the exception with the stack trace of the minified bundle, the page URL, the user agent, and the breadcrumbs: the API calls with their method, URL, and status, the navigations, the clicked elements, and the console messages. It holds no request or response body, no IP address, and no user. The values of the query parameters `q`, `name`, `confirm`, and `code` are `[Filtered]`.
- Each page load and navigation sends a trace. The API calls carry the `sentry-trace` and `baggage` headers, and no other host gets them.
- 10% of the sessions, and each session with an error, send a session replay. A replay masks all text and inputs, and blocks all images and media.

In both Sentry projects, keep the data scrubbing of the Security & Privacy settings on, and turn on the setting that stops storing IP addresses, as a second layer.

## Symbols service

Set `SYMBOLS_URL` for every PHP process: the web server and each Messenger worker. [Manual deployment](manual.md#symbols-service) installs and runs the service, and [dockerised deployment](dockerised.md#symbols-container) runs its image.

| Where the service runs | `SYMBOLS_URL` |
|---|---|
| On the host of the PHP process | `http://127.0.0.1:<SYMBOLS_PORT>` |
| On another host | `http://<host>:<SYMBOLS_PORT>` |
| In a container on the Docker network of the PHP containers | `http://<container name>:3000` |

- The `.env` default `http://symbols:3000` resolves only in the Compose network.
- Give the scheme, the host, and the port only. The client requests the absolute path `/v1/symbols`, so it drops a path in `SYMBOLS_URL`. With `http://proxy/symbols/`, the request goes to `http://proxy/v1/symbols`. A reverse proxy must serve the service at its root path.

From the host or the container of each PHP process, run the `/healthz` check of [manual deployment](manual.md#run) against the `SYMBOLS_URL` value.

## Mercure hub

The hub runs outside the application, behind nginx. [Manual deployment](manual.md#mercure-hub) configures it.

| Variable | Value |
|---|---|
| `MERCURE_URL` | The URL that the PHP processes publish to: `http://127.0.0.1:8000/.well-known/mercure` on the host of the hub. In a container or on another host, the `MERCURE_PUBLIC_URL` value, because `127.0.0.1` in a container is the container itself. |
| `MERCURE_PUBLIC_URL` | `https://<site>/.well-known/mercure`, for example `https://beta.slopmower.app/.well-known/mercure` |
| `MERCURE_JWT_SECRET` | The value of the hub |

Check each site:

```sh
curl -sS -o /dev/null -w '%{http_code}\n' 'https://slopmower.app/.well-known/mercure?topic=x'
```

The command gives `401`, because the request has no token.
