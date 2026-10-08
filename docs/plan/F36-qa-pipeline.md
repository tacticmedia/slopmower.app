# F36 QA pipeline

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-08 for a complete QA pipeline on GitHub Actions that runs every quality check and every test. It must be verified locally with nektos/act, and that verification is part of the local QA. It runs whenever a test or QA mechanism changes. This feature implements the backlog item "Quality-assurance pipeline".

## Goal

- A push to any branch and every pull request run all gates of `make check`, plus the Composer and npm checks, on GitHub Actions, through the `Makefile` targets.
- `make check` also runs the workflow locally with `act` when a file that defines a gate, the stack, or the workflow changed since the last green local run.

## Facts

- `make check` runs `cs stan lint test front ui e2e`.
  - `cs`, `stan`, `lint`, `test`, and `e2e` run in the `php` container of the compose stack, which also runs `worker`, `symbols`, and `database`.
  - `front` and `ui` run on the host with Node 24. `ui` needs Chromium on the host.
  - `make up` is `docker compose up -d --wait --build`, and `make db-test` creates and migrates `app_test` and `app_e2e`.
- `docker/frankenphp/docker-entrypoint.sh` runs `composer install` when `vendor/` is empty, waits for the database, and migrates the dev and test databases. Compose reads `MERCURE_JWT_SECRET` and the other interpolated values from the committed `.env`.
- The repository has the GitHub remote `git@github.com:tacticmedia/holistic-review.git`. Sessions never push, so the workflow runs on GitHub after Jan pushes.
- Local machine on 2026-10-08:
  - `act` 0.2.89 (Homebrew);
  - Docker 29.4.0, linux/arm64;
  - the images `catthehacker/ubuntu:act-24.04` and `catthehacker/ubuntu:act-latest`;
  - `~/.actrc` maps `ubuntu-latest` to `catthehacker/ubuntu:act-latest`.
- `act` 0.2.89 (`act --help`):
  - `-b, --bind` binds the working directory into the job container instead of copying it;
  - the Docker socket is bind-mounted into the job container unless `--container-daemon-socket -` is given;
  - `--env-file` defaults to `.env`, which here is the Symfony file;
  - `--artifact-server-path` starts the artifact server;
  - a cache server runs by default;
  - `act` reads `./.actrc` and `~/.actrc`, and sets `ACT=true` in the job.
- A step that runs `docker compose` in an `act` job talks to the host daemon. A bind mount of the compose file, `.:/app`, resolves on the host, so the job must see the checkout at its host path. Binding the developer checkout itself would let the job run `npm ci` and `composer install` with Linux binaries into the host's `node_modules/` and `vendor/`. It would also, with the default project name `holistic-review`, replace the developer stack.

## Decisions

D62 in [architecture section 11](../architecture.md#11-decisions).

- **One workflow, `.github/workflows/qa.yml`,** on `push` (all branches) and `pull_request`, with one job on `ubuntu-24.04`:
  1. checkout;
  2. Node 24 with the npm cache;
  3. `npm ci`;
  4. Chromium with its system packages for `make ui`;
  5. `make up` and `make db-test`;
  6. one step per gate: `make cs`, `make stan`, `make lint`, `make test`, `make front`, `make ui`, `make e2e`, `make composer c="validate --no-check-publish"`, `make composer c="audit"`, `npm audit`, and `npm audit --prefix symbols`.

  Each gate step runs after a failed gate too (`if: ${{ !cancelled() }}` once the stack is up), so one run reports every gate. A gate has no second command definition in the workflow.
- **Steps that do not mirror a local gate** are environment setup only: Node, Chromium packages, caches, and artifacts.
- **Caches:**
  - Composer cache on `composer.lock`;
  - npm caches on `package-lock.json` and `symbols/package-lock.json`;
  - Playwright browsers on the Playwright version;
  - Docker build layers on `Dockerfile`, through buildx with the GitHub Actions cache. A cache step that `act` cannot serve is skipped under `act` (`if: ${{ !env.ACT }}`).
- **Artifacts on failure:** `test-failures/`, `var/ui/test-results/`, and `var/log/`.
- **Actions** are pinned by commit SHA, with the version as a comment, each version looked up at the time of the work.
- **Local verification, `make act`:**
  - It copies the working tree without ignored files into `var/act/workspace/`, with `rsync` and a `.gitignore` filter, keeping the `vendor/` and `node_modules/` of earlier runs there.
  - It runs `act --bind` there, on `ubuntu-24.04`, with `catthehacker/ubuntu:act-24.04`. Its own env file replaces `.env`, and the artifact server stores into `var/act/artifacts/`.
  - The compose project of the job has its own name and its own ports (`ACT_HTTP_PORT`, `ACT_DATABASE_PORT`, `ACT_UI_PORT` with defaults, overridable in `.env.make.local`). It never touches the developer stack.
  - After the run, `make act` removes that compose project with its volumes. Each run starts from an empty database, as on GitHub.
  - The committed `.actrc` holds the flags that every local run needs.
- **Trigger:** `make check` runs `make act` unless a green `make act` has seen the current content of the files that define the workflow, the stack, or a gate:
  - `.github/workflows/*`, `.actrc`, and the env file of `act`;
  - `Makefile`, `compose.yaml`, `compose.override.yaml`, `Dockerfile`, and `docker/`;
  - `phpunit.dist.xml`, `phpstan.dist.neon`, and the php-cs-fixer configuration;
  - `playwright.config.ts`, `vite.config.ts`, and `tsconfig*.json`;
  - `package.json`, `package-lock.json`, `symbols/package.json`, `symbols/package-lock.json`, `composer.json`, and `composer.lock`;
  - `.env.test` and `.env.e2e`.

  A green `make act` records the hash of their names and content in `.git/act-verified/`, which every worktree shares, so a checkout with the same files skips `act`. A second `make act` waits for the first. In CI and under `act`, the `check` target never starts `act` (`CI` or `ACT` is set). The workflow calls the gate targets, not `check`.

## Depends on

M01, M34b, M34c. F27 (the `symbols` service in the stack).

## In scope

- `.github/workflows/qa.yml`, `.actrc`, the `act` env file, and `Makefile` (`act`, the stamp rule, and `check`).
- A shell script under `bin/` if `make act` needs more than a few lines. Scripts are shell or PHP, no Python.
- A spike first, recorded in the report:
  - `act` 0.2.89 with `--bind` runs a job that calls `make up`, and the compose bind mounts resolve on the host;
  - the job image has the Docker CLI with the compose plugin;
  - `make ui` finds Chromium in the job container.

  If `act` cannot run the compose stack in a job, stop and report with the output.
- Docs:
  - the root `README.md`: the workflow next to `make check`, and `make act`;
  - `docs/plan/README.md`: the command table, the environment table, and the test conventions;
  - `docs/developer-guide.md`: the runtime and test sections;
  - `docs/architecture.md`: section 10, the test strategy, and D11 if hosting text changes;
  - `docs/requirements.md`: the Non-goals row that names CI as operator work.
- Delete `docs/backlog/qa-pipeline.md` and its row in `docs/backlog/README.md`.

## Out of scope

- Branch protection on `main`: a GitHub setting that Jan owns.
- Deployment and release jobs.
- Running `act` on every `make check`: only on a changed QA file.

## Acceptance tests

1. **Local, `act`:** `make act` runs the whole workflow green on this machine, with the developer stack still running and untouched before and after. `docker compose ps` shows the same containers, and `curl -s localhost:8000/login` shows "Sign in as jan".
2. **Each gate fails the run:** for each gate, a deliberate failure in `var/act/workspace/` fails the `act` run at that gate, and the other gates still report. One temporary failure per gate type is enough: a cs violation, a PHPStan error, a failing PHPUnit test, a failing Vitest test, a failing UI spec, a failing E2E test, and an invalid `composer.json`. None of these failures is committed.
3. **Trigger:** after a green `make act`, `make check` does not run `act` again, also in another worktree with the same files. After a change to the content of `phpunit.dist.xml`, it does. Under `CI=true`, it never does.
4. **Artifacts:** a failed `act` run stores the failure files in `var/act/artifacts/`.
5. **No second definition:** every gate step of `qa.yml` is a `make` target or an audit command.

## Verification

- `make check` is green, including one `make act` run.
- Manual check: `act -l` in `var/act/workspace/` lists the job. The workflow file passes `actionlint` if it is available through `docker run rhysd/actionlint`; this is a check, not a dependency.
