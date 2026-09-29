# M01 Local Docker environment

Workflow, conventions, and status: [README](README.md).

## Goal

One `make up` starts FrankenPHP, the Messenger worker, and PostgreSQL 18:

- FrankenPHP serves the dev app on port 8000 with the Mercure hub, and the E2E site on port 8081;
- the local repositories are mounted read-only;
- Panther works inside the container.

## Depends on

M00.

## In scope

- Dockerfile, Caddyfile, entrypoint, compose files, Makefile.
- The `e2e` kernel environment, decision P3.
- `/healthz`.
- The first migration.
- A Panther smoke test.

## Out of scope

- TLS, a production image, CI. Jan handles operations.

## Files (representative)

- New: `Dockerfile`, `.dockerignore`, `docker/frankenphp/Caddyfile`, `docker/frankenphp/docker-entrypoint.sh`, `docker/frankenphp/conf.d/10-app.ini`, `docker/frankenphp/conf.d/20-app.dev.ini`, `Makefile`, `.env.e2e`, `src/Controller/HealthController.php`, `templates/health.html.twig`, `migrations/Version*.php`, `tests/Functional/HealthEndpointTest.php`, `tests/Functional/EnvironmentConfigTest.php`, `tests/E2E/BrowserSmokeTest.php`.
- Changed: `composer.json`, `compose.yaml`, `compose.override.yaml`, `.env`, `.env.dev`, `.env.test`, `src/Kernel.php`, `config/packages/framework.yaml`, `config/packages/doctrine.yaml`, `config/packages/messenger.yaml`.

## Tasks (ordered)

1. Set `extra.symfony.docker` to `false` in `composer.json`, decision P5.
2. Take the Dockerfile, Caddyfile, entrypoint, and PHP ini files from the [dunglas/symfony-docker](https://github.com/dunglas/symfony-docker) template. Follow its guide "Using Symfony Docker with an existing project". Put the files under `docker/frankenphp/` and adapt them as the next tasks say.
3. Dockerfile, base stage:
   - image `dunglas/frankenphp:1.12.7-php8.5-trixie`. Check the tag on Docker Hub;
   - apt packages `git`, `unzip`, `acl`;
   - `install-php-extensions @composer intl opcache pdo_pgsql zip`.
4. Dockerfile, dev stage:
   - `xdebug`, with `XDEBUG_MODE=off` as the default;
   - apt packages `chromium` and `chromium-driver`. Debian provides both, so `dbrekelmans/bdi` is not needed;
   - `PANTHER_NO_SANDBOX=1`, `PANTHER_CHROME_ARGUMENTS=--disable-dev-shm-usage`, `PANTHER_CHROME_BINARY=/usr/bin/chromium`;
   - no system-wide `safe.directory`. M09 passes it per command.
5. Caddyfile, site `{$SERVER_NAME}`, which is `:80`:
   - a `mercure` block: publisher and subscriber JWT keys from `MERCURE_JWT_SECRET`, `cors_origins http://localhost:8000`, no anonymous subscribers;
   - `php_server` with a worker for `public/index.php`, plus `watch`.
6. Caddyfile, site `:8081`: its own `mercure` block, and `php_server` with `env APP_ENV e2e` in classic mode, without a worker. Check the directive syntax against the installed FrankenPHP and Mercure documentation.
7. Entrypoint:
   - run `composer install` when `vendor/` is missing;
   - wait for the database;
   - run the dev migrations;
   - create `app_test` when it is missing, and migrate it;
   - run `setfacl` on `var/`.
8. `compose.yaml`, service `php`:
   - ports `8000:80`;
   - volumes `.:/app`, `tenant_data:/srv/tenants`, `${LOCAL_REPOSITORIES_HOST_DIR:-./var/local-repositories}:/repositories:ro`, `caddy_data`, `caddy_config`;
   - environment: `SERVER_NAME=:80`, `DATABASE_URL` with host `database` and `serverVersion=18`, `APP_LOCAL_SIGN_IN=${APP_LOCAL_SIGN_IN:-}`;
   - healthcheck on `/healthz`.
9. Keep the per-environment values out of the compose `environment:` lists. Real environment variables win over `.env` files, so a compose value would leak into the `test` and `e2e` processes of the same container. Put them into the `.env` files instead:
   - `.env`: `APP_LOCAL_REPOSITORIES_DIR=` means local repositories are off, `APP_LOCAL_SIGN_IN=`, `APP_TENANT_DATA_DIR=var/tenants`, `LOCAL_REPOSITORIES_HOST_DIR=./var/local-repositories`, `MERCURE_JWT_SECRET`, and `DATABASE_URL` with `serverVersion=18`;
   - `.env.dev`: `APP_LOCAL_REPOSITORIES_DIR=/repositories`, `APP_TENANT_DATA_DIR=/srv/tenants`, `MERCURE_URL=http://localhost/.well-known/mercure`, `MERCURE_PUBLIC_URL=http://localhost:8000/.well-known/mercure`;
   - `.env.test` and `.env.e2e`: `PANTHER_EXTERNAL_BASE_URI=http://localhost:8081`, `APP_LOCAL_REPOSITORIES_DIR=var/test/local-repositories`, `APP_TENANT_DATA_DIR=var/test/tenants`, and both Mercure URLs on `http://localhost:8081/.well-known/mercure`.
10. `compose.yaml`, service `worker`:
    - the same image and volumes as `php`;
    - command `bin/console messenger:consume async --time-limit=3600 --memory-limit=256M -vv`;
    - environment `MERCURE_URL=http://php/.well-known/mercure`. The worker runs only the `dev` environment, so this value cannot leak;
    - it starts when `php` is healthy.
11. `compose.yaml`, service `database`: image `postgres:${POSTGRES_VERSION:-18}-alpine` with the volume `database_data:/var/lib/postgresql`. PostgreSQL 18 stores data under `/var/lib/postgresql/18/docker`, so do not mount `/var/lib/postgresql/data`. No init scripts: one database role, decision P13. `compose.override.yaml` publishes `127.0.0.1:5432:5432`.
12. Add `e2e` to `Kernel::getAllowedEnvs()`.
13. Configuration per environment:
    - `when@e2e`: native sessions, Doctrine `dbname_suffix: _test`, Messenger transport `async: sync://`;
    - `when@test`: Messenger transport `async: sync://`.
14. `HealthController` at `/healthz`: an HTML page with a `<title>` and a `<span id="js">`. An inline script sets the span text to `ok`.
15. Run `make migration`. It yields only the `messenger_messages` table, through Doctrine's schema listener, so the worker can start.
16. Write the `Makefile` with the targets in the [README](README.md#environment-and-commands). `make e2e` and `make front` skip npm while there is no `package.json`.

## Acceptance tests

- **F `HealthEndpointTest`** proves that the app answers inside the container.
  - "GET /healthz returns 200 with an HTML page"
- **E `BrowserSmokeTest`** proves Chromium, chromedriver, Panther 2.4 with PHPUnit 13, and the `e2e` environment. The combination of Panther 2.4 and PHPUnit 13 is an open architecture risk. If this test fails, stop and report.
  - "The browser on port 8081 sees the text ok in the js marker"
- **F `EnvironmentConfigTest`** proves that the test kernel does not reach the dev database or an async queue.
  - "The test kernel uses the sync transport and the database app_test"

## Verification commands

```sh
make up
docker compose ps
curl -fsS localhost:8000/healthz
curl -s -o /dev/null -w '%{http_code}' localhost:8000/.well-known/mercure
docker compose exec php php -v
docker compose exec php git --version
docker compose exec database psql -U app -Atc 'show server_version'
docker compose exec php touch /repositories/probe
make console c="about --env=e2e"
docker compose logs worker
make check
```

Expected: the Mercure URL answers with a status other than 404. The server version is 18.x. The `touch` fails, because the mount is read-only.

## Done

From a fresh clone, `make up` followed by `make check` is green without host PHP.
