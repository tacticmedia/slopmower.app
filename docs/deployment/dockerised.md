# Dockerised deployment

The `Dockerfile` builds the application image and the `symbols` image. One release step migrates the database, then the web container and the worker containers start from the same image. nginx terminates TLS in front of the web container and forwards `/.well-known/mercure` to the external Mercure hub. [Deployment](README.md) describes the environment variables. [Manual deployment](manual.md) runs the same parts on the host.

## Images

```sh
docker build --target frankenphp_prod --tag slopmower:<release> .
docker build --target symbols --tag slopmower-symbols:<release> .
```

| Target | Runs |
|---|---|
| `frankenphp_prod` | FrankenPHP in worker mode with `APP_ENV=prod`, as `www-data`. The web container, every worker container, and the release step. |
| `symbols` | The `symbols` service on port 3000, as `node` |

- The build installs the Composer packages without the dev packages, dumps `.env.local.php` from `.env`, warms the `prod` cache, and builds the UI with Vite.
- The image has no `tests/` and no `.env.dev`. From `docs/` it has only `docs/user/` and `docs/specs/schemas/`, which `/docs` serves.
- PHP uses `php.ini-production` with `opcache.validate_timestamps = 0` and `zend.exception_ignore_args = On`. A changed PHP file needs a new image.
- `make prod-check` builds the image, runs `lint:container`, and requests `/login`. The QA workflow runs it on each push.

## Environment

Give every container of the application the same variables. Copy `.env.prod.dist` to a file outside the repository, for example `prod.env`, set the values, and pass it with `--env-file prod.env`. The variables of the container win over `.env.local.php`.

- `--env-file` keeps each value literally, also the quotes. Remove the quotes of `.env.prod.dist`, for example `DATABASE_PASSWORD=<raw password>`.
- An env file holds one line per variable. Give the PEM key with its line breaks through `--env GITHUB_APP_PRIVATE_KEY="$(cat <key file>)"`, or through the secrets vault.
- Set `APP_TENANT_DATA_DIR=/srv/slopmower/tenants`, a [volume](#volumes) of the image.
- Do not set `APP_SHARE_DIR`. The build compiles its `.env` value `var/share` into the container, so the variable of a container has no effect.
- Set `SYMBOLS_URL` to the [symbols container](#symbols-container), and `MERCURE_URL` to the `MERCURE_PUBLIC_URL` value, see [Mercure hub](README.md#mercure-hub).
- Set `SYMFONY_TRUSTED_PROXIES=PRIVATE_SUBNETS`. The web container sees the requests of nginx from the Docker network.

All containers below use one Docker network, so that they reach each other by name:

```sh
docker network create slopmower
```

## Volumes

| Path in the image | Containers | Content |
|---|---|---|
| `/srv/slopmower/tenants` | Web and every worker | `APP_TENANT_DATA_DIR`: the clones of the tenants. Keep it on a persistent, encrypted volume. |
| `/app/var/share` | Web and every worker | The share directory: `cache.app` with the checkpoint of the schedule and the `messenger:stop-workers` signal, and the RDS credential pool. Local to the host. |
| `/app/var/sessions` | Web | The browser sessions. Without a volume, a new web container signs every user out. |
| `/app/var/mcp-sessions` | Web | The MCP sessions (D40). Without a volume, a new web container ends every MCP session. |

The image creates each path as `www-data`, so a new named volume gets that owner. A bind mount needs a host directory that the user ID 33 can write.

## Release

For each release, start the new `symbols` container, then run the release step, then replace the web and the worker containers.

```sh
docker run --rm --network slopmower --env-file prod.env --env MIGRATE_DB=latest slopmower:<release>
```

- With `MIGRATE_DB`, the entrypoint waits up to 60 s for the database, then runs `doctrine:migrations:migrate "$MIGRATE_DB" --all-or-nothing --allow-no-migration`. `MIGRATE_DB` is the version argument of the command, for example `latest`.
- After the migration, it writes `Migrations are done` and exits with 0. A failed migration exits with another code. `--all-or-nothing` runs all migrations of the run in one transaction, so PostgreSQL rolls them all back.
- `MIGRATE_DB_AND_CONTINUE=1` starts the command after the migration. Development uses it. The web and the worker containers set neither variable, so they never migrate.

## Web container

```sh
docker run --detach --name slopmower-web --network slopmower --restart unless-stopped --env-file prod.env \
    --volume slopmower-tenants:/srv/slopmower/tenants --volume slopmower-share:/app/var/share \
    --volume slopmower-sessions:/app/var/sessions --volume slopmower-mcp-sessions:/app/var/mcp-sessions \
    --publish 127.0.0.1:8080:80 slopmower:<release>
```

- FrankenPHP serves HTTP on port 80 in worker mode, without TLS and without a Mercure hub. `SERVER_NAME` changes the address, default `:80`.
- The health check of the image requests the Caddy admin endpoint `http://localhost:2019/metrics`.

## Worker containers

```sh
docker run --detach --name slopmower-worker --network slopmower --restart unless-stopped --no-healthcheck --stop-timeout 610 --env-file prod.env \
    --volume slopmower-tenants:/srv/slopmower/tenants --volume slopmower-share:/app/var/share \
    slopmower:<release> bin/console messenger:consume async scheduler_default --time-limit=3600 --memory-limit=96M
```

- [Messenger worker](manual.md#messenger-worker) describes both transports and the daily jobs.
- `--no-healthcheck`: the health check of the image requests the Caddy admin endpoint, which a worker does not run.
- `--restart unless-stopped` starts the worker again after `--time-limit`. `--memory-limit=96M` is below the `memory_limit` of 128M of `php.ini-production`, so the worker stops after a job before PHP fails.
- A worker stops after its current job on `SIGTERM`, through the `pcntl` extension. `--stop-timeout 610` is above the 600 s limit of a clone or fetch job, the longest job, so `docker stop` does not kill a worker in a job. `bin/console messenger:stop-workers` in a container with the share volume stops every worker after its current job.

## Symbols container

```sh
docker run --detach --name slopmower-symbols --network slopmower --restart unless-stopped slopmower-symbols:<release>
```

- Set `SYMBOLS_URL=http://slopmower-symbols:3000` for the web and the worker containers.
- The image has no health check. `GET /healthz` gives 200 when the service is ready. [Manual deployment](manual.md#symbols-service) describes the service, the network access, and a new release.

## nginx

nginx on the host terminates TLS and forwards each site to the web container:

```nginx
upstream slopmower {
    server 127.0.0.1:8080;
}

server {
    server_name slopmower.app;
    # TLS

    location / {
        proxy_pass http://slopmower;
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Host  $host;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # The location /.well-known/mercure of the manual deployment
}
```

- Without `X-Forwarded-Proto` and `SYMFONY_TRUSTED_PROXIES`, the GitHub callback URL is `http://`, and GitHub refuses the sign-in.
- The `Host` header must be `APP_PUBLIC_HOST`, because the MCP endpoint `/_mcp` accepts requests for this host only.

## Mercure hub

The image has no Mercure hub. Run the hub and its nginx location as [manual deployment](manual.md#mercure-hub) describes.
