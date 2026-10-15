# F50 Production image

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-14: a production stage in the Docker build, as a real deployment target next to the manual install, with the deployment docs split in two, the migrations as in the cognetiq-io entrypoint, and a build of the image in CI.

## Goal

- `docker build --target frankenphp_prod` gives an image that runs the web server and the Messenger worker in production.
- One release step migrates the database. The web and worker containers do not migrate.
- CI builds and starts the image on every push, so a dev package or a dev file cannot break production unseen.

## Facts

- The `Dockerfile` has the stages `frankenphp_upstream`, `node_upstream`, `symbols`, `frankenphp_base`, and `frankenphp_dev`. `frankenphp_dev` uses `php.ini-development`. The `symbols` stage is already a production image.
- `docker/frankenphp/docker-entrypoint.sh` and `docker/frankenphp/Caddyfile` are for local development only:
  - The entrypoint installs Composer packages when `vendor/` is empty, and it migrates the dev and test databases. It also sets the ACLs of `var/`.
  - The Caddyfile has worker mode with `watch`, an embedded Mercure hub with a `localhost` CORS origin, and the e2e site on `:8081`.
- `src/Docs/DocsCatalog.php` reads `docs/user/*.md`, `docs/user/skill/SKILL.md`, and `docs/specs/schemas/*.schema.json` at runtime. `.dockerignore` excludes `docs/` and `**/*.md`.
- The UI is the Vite build `npm run build`, which writes `public/build`, `public/entrypoints.json`, and `public/manifest.json`.
- The Messenger transports have `auto_setup=0`, and a migration creates their tables.
- `docs/deployment.md` describes a host install: PHP processes behind nginx, a Messenger worker under a process manager, the `symbols` service, and an external Mercure hub.
- `php.ini-production` sets `zend.exception_ignore_args = On`. With Off, exception traces keep the function arguments, so Sentry stack frames and the `FlattenException` of Messenger carry request text and row values (architecture section 12, D66).
- The cognetiq-io entrypoint:
  - migrates when `MIGRATE_DB` is set;
  - exits after the migration unless `MIGRATE_DB_AND_CONTINUE` is set, so one container per cluster migrates and stops;
  - reinstalls the Composer packages when the md5 of `composer.lock` in `vendor/` differs.

## Decisions

D74 in [architecture section 11](../architecture.md#11-decisions).

- **Two new stages.**
  - `frankenphp_prod_builder` installs the packages with `--no-dev`, dumps a classmap-authoritative autoloader and `.env.local.php`, warms the prod cache, and copies the Vite build of a new `assets` stage.
  - `frankenphp_prod` starts from `frankenphp_base`, so `git` and the PHP extensions are there, and runs as `www-data`.
- **`php.ini-production`** plus `docker/frankenphp/conf.d/20-app.prod.ini`: `opcache.validate_timestamps = 0`, and `zend.exception_ignore_args = On` set explicitly.
- **`docker/frankenphp/Caddyfile.prod`:** `{$SERVER_NAME::80}`, worker mode without `watch`, no embedded Mercure hub, and no e2e site. nginx terminates TLS in front of it, and the external hub stays.
- **One entrypoint for dev and prod:**
  1. Dev and test only, for `frankenphp`: `composer install` when `vendor/composer.lock.md5` does not match `composer.lock`. The md5 is written after a successful install, so a failed install runs again on the next start.
  2. With `MIGRATE_DB`: wait for the database, then `doctrine:migrations:migrate "$MIGRATE_DB" --no-interaction --all-or-nothing --allow-no-migration`. In dev, also create and migrate the test database. Without `MIGRATE_DB_AND_CONTINUE`, exit 0.
  3. Dev only: the ACLs of `var/`.
- **Dev keeps its behaviour.** In `compose.yaml`, the php service sets `MIGRATE_DB=latest` and `MIGRATE_DB_AND_CONTINUE=1`, and the worker sets neither.
- **Differences from cognetiq-io:**
  - no `messenger:setup-transports`, because the migrations create the queue tables;
  - no rollup branch, because this repository keeps every migration;
  - no supervisord, because web and worker are separate containers;
  - no automatic fixtures, because F49 loads them on demand;
  - the prod cache is warmed in the build, because the hosts come from `%env()%` at runtime.
- **`.dockerignore`** keeps `docs/user/**` and `docs/specs/schemas/**`, and excludes `.env.dev`.
- **CI.** `qa.yml` builds `--target frankenphp_prod` and starts it, with `bin/console lint:container`, `bin/console debug:container --env-vars`, and `GET /login` with a non-default `APP_PUBLIC_HOST`.
- **Docs.**
  - `docs/deployment/README.md` holds what both methods share: the environment variables of the database, the usage and rate limits, the symbols URL, and the Mercure variables of the application.
  - `docs/deployment/manual.md` is the host install of `docs/deployment.md`.
  - `docs/deployment/dockerised.md` is new: the images, the release step, the web and worker containers, the volumes, nginx, and the external hub.

## Depends on

F49 (Foundry as a dev package, which the prod build must leave out).

## In scope

1. `Dockerfile`: the `assets`, `frankenphp_prod_builder`, and `frankenphp_prod` stages.
2. `docker/frankenphp/docker-entrypoint.sh`, `docker/frankenphp/Caddyfile.prod`, `docker/frankenphp/conf.d/20-app.prod.ini`, `.dockerignore`, `compose.yaml`.
3. `.github/workflows/qa.yml`: the prod build and its smoke checks.
4. `docs/deployment.md` becomes `docs/deployment/README.md` and `docs/deployment/manual.md`. New `docs/deployment/dockerised.md`. Every link to `deployment.md` is updated.
5. D74. The candidate "Entrypoint and a stale lock" in `features.md` is resolved.

## Out of scope

- A registry push, image tags, and a deployment pipeline.
- Health checks of an orchestrator. The CI smoke checks stay the proof.
- Sentry configuration (F51).

## Acceptance tests

- CI: the prod build, `lint:container`, and `GET /login` with 200 pass in `qa.yml` and in `make act`.
- The image has no `vendor/zenstruck` and no `tests/`, and `vendor/autoload.php` loads no dev package.
- `docker run --rm -e MIGRATE_DB=latest <image>` with the dev database settings exits 0 after "Migrations are done".
- A web container answers `/login` and `/docs`, and a worker container from the same image consumes a queued job.
- `make up` still migrates the dev and test databases, and a changed `composer.lock` installs again at the next start.

## Verification

- `make check` and `make act` are green.
- By hand: the three container runs of the acceptance tests against the dev database.
