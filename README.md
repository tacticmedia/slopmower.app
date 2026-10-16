# Slopmower

Slopmower lets you review a full codebase at one commit, not only a diff. You read the code in a view that works like a GitHub pull request review, and you comment on lines, line ranges, symbols, or files. Each comment is a change request. Your coding agent, for example Claude Code, reads the requests over MCP, asks questions, makes the changes, and links its commits. You then verify or reopen each request.

This page tells you how to run Slopmower on your computer and how to run the checks. The user documentation is in [docs/user/](docs/user/index.md). The developer documentation starts at [docs/plan/README.md](docs/plan/README.md).
The [developer guide](docs/developer-guide.md) maps the code: domain areas, namespaces, traced flows, and where to start.

## Quick start

```sh
export LOCAL_REPOSITORIES_HOST_DIR="$HOME/dev" APP_LOCAL_SIGN_IN=jan
make up
npm ci && npm run build
```

Then open **http://localhost:8000** and click **Sign in as jan**. The repositories under `LOCAL_REPOSITORIES_HOST_DIR` are listed on the Repositories page. The sections below explain each step.

| What | URL |
|---|---|
| The app | http://localhost:8000 |
| MCP server for your agent | http://localhost:8000/_mcp |
| User documentation | http://localhost:8000/docs |

A port other than 8000: see [Port overrides](#port-overrides-and-saved-variables).

## What to install

- Docker Desktop with Docker Compose v2 or later: the `docker compose` command.
- `git` and `make`.
- Node.js 24 with npm. npm builds the front-end assets on the host (`npm run build`, or `npm run dev` for hot reload). Everything else runs in containers: FrankenPHP serves the app, the API, MCP, and the built-in Mercure hub in worker mode; a second container runs the Messenger worker; PostgreSQL holds the data.
- For `make act`: [act](https://github.com/nektos/act) 0.2.89 or later and `rsync`. `make check` runs `make act` after a change to the workflow, the stack, or a gate. See [Run the checks](#run-the-checks).
- Optional: Claude Code or another agent that can use an HTTP MCP server.

## Start Slopmower

1. Clone the repository and open a terminal in it:

   ```sh
   git clone <repository URL> slopmower
   cd slopmower
   ```

2. Set the directory that holds your git repositories, as an absolute path, and the name of your local user:

   ```sh
   export LOCAL_REPOSITORIES_HOST_DIR="$HOME/dev"
   export APP_LOCAL_SIGN_IN=jan
   ```

   Compose reads both variables at each `make up`. To keep them, put them into `.env.make.local`. See [Port overrides and saved variables](#port-overrides-and-saved-variables).

3. Start the containers `php`, `worker`, and `database`:

   ```sh
   make up
   ```

   The first run builds the image and installs the Composer packages, so it takes longer. The `php` container migrates the database when it starts.

4. Build the web interface:

   ```sh
   npm ci
   npm run build
   ```

   For hot reload, keep `npm run dev` running instead of `npm run build`.

5. Open http://localhost:8000 and click **Sign in as jan**. The button shows the name from `APP_LOCAL_SIGN_IN`. The first sign-in creates your local account.

To stop Slopmower, run `make down`. The database and the clones stay in Docker volumes.

[Run it locally](docs/user/local-run.md) has the details: how Slopmower reads the repository directory, when it fetches new commits, and the limits of local sign-in.

## Review a local repository

1. Open **Repositories**. The **Local directories** table lists the git repositories directly under `LOCAL_REPOSITORIES_HOST_DIR`.
2. Click **Connect** next to a repository.
3. When the status under **Connected repositories** is **Ready**, click the repository name.
4. Under **Start a review**, keep the default branch in **Ref**, or type a branch, tag, or commit SHA. Click **Start review**. The file tree opens.

[Reviewing](docs/user/reviewing.md) tells you how to comment, verify, reopen, and close.

## Connect your agent

Follow [Connect your agent](docs/user/connect-your-agent.md): create a token in **Settings**, put it into `SLOPMOWER_TOKEN`, add `.mcp.json` to the repository that you review, and install the skill. For a local run, use these URLs:

- MCP server: `http://localhost:8000/_mcp`
- Skill: `http://localhost:8000/docs/skill/SKILL.md`

Then click **Agent prompts** in the review header, then **Copy prompt**, and paste the prompt into the agent.

## Run the checks

The stack must be running. `make up` creates and migrates the test databases `app_test` and `app_e2e`. After a new migration, migrate them again:

```sh
make db-test
```

Run every gate:

```sh
make check
```

`make check` runs these targets in order. You can run each one alone:

| Target | Runs |
|---|---|
| `make cs` | php-cs-fixer, dry run. `make cs-fix` applies the fixes. |
| `make stan` | PHPStan level 8 |
| `make lint` | `lint:container`, `lint:yaml config`, `lint:twig templates`, `doctrine:schema:validate` |
| `make test` | PHPUnit unit and functional suites. Builds `public/build-e2e` first when it is missing. |
| `make front` | `tsc` and Vitest, on the host |
| `make ui` | Playwright UI suite against a mocked API, on the host, port `UI_PORT`, default 4173 |
| `make e2e` | Full-stack suite: Playwright in the `php` container against the e2e site on port 8081 inside the container |
| `make act` | The GitHub Actions workflow, locally. Only when a file of the workflow, the stack, or a gate is newer than the last green run. |

One test: `docker compose exec php bin/phpunit --filter StartReviewTest`. Add `--debug` for more output. `make help` lists all targets.

### GitHub Actions

The workflow [`.github/workflows/qa.yml`](.github/workflows/qa.yml) runs on every push and every pull request. It starts the stack with `make up` and `make db-test` on a clean runner, then runs each gate as its `make` target, plus `composer validate`, `composer audit`, `npm audit` for both lock files, and `make prod-check`, which builds the production image and requests `/login` from it. Each gate also runs after a failed gate, so one run reports all of them. A failed run keeps `test-failures/`, `var/ui/test-results/`, and `var/log/` as the artifact `failures`.

`make act` runs the same workflow on your computer with act:

- It copies the working tree without the git-ignored files into `var/act/workspace/`. As on GitHub, the run installs `vendor/` and `node_modules/` itself.
- The job uses its own compose project, `slopmower-act`, on the ports 8290, 5490, and 4290. Your stack keeps running. After the run, `make act` removes that project with its volumes.
- The failure files go to `var/act/artifacts/`. The caches stay in the default directories of act under `~/.cache/`.
- Only one act run of the workflow can run on a Docker daemon at a time.

`make check` runs `make act` last when no green `make act` has seen the current content of the files in `ACT_INPUTS` of the `Makefile`. A green `make act` records the hash of that content in `.git/act-verified/`, which every worktree of the repository shares. With `CI` or `ACT` set, `make check` never runs act. Run act only through `make act`: the workflow refuses a run outside `var/act/workspace/`.

## Port overrides and saved variables

The `Makefile` reads the git-ignored file `.env.make.local`. Each line needs `export`, or make does not pass the variable to Docker Compose. Example:

```make
export HTTP_PORT=8001
export DATABASE_PUBLISHED_PORT=5433
export UI_PORT=4174
export LOCAL_REPOSITORIES_HOST_DIR=$(HOME)/dev
export APP_LOCAL_SIGN_IN=jan
```

| Variable | Default | Meaning |
|---|---|---|
| `HTTP_PORT` | 8000 | Host port of the app, the MCP server, and the Mercure hub |
| `DATABASE_PUBLISHED_PORT` | 5432 | Host port of PostgreSQL. The app connects to `database:5432` in the compose network. |
| `UI_PORT` | 4173 | Port of the preview server of `make ui` |
| `ACT_HTTP_PORT`, `ACT_DATABASE_PUBLISHED_PORT`, `ACT_UI_PORT` | 8290, 5490, 4290 | The same ports for the stack of `make act`. The defaults are in `.github/act.env`. |
| `LOCAL_REPOSITORIES_HOST_DIR` | `./var/local-repositories` | Host directory that Docker mounts read-only at `/repositories` |
| `APP_LOCAL_SIGN_IN` | empty | Login of the local user. Empty means no local sign-in. |

The file uses make syntax: write `$(HOME)`, not `$HOME`, and no quotes. With another `HTTP_PORT`, use that port in the URLs above and in `.mcp.json`. Start and stop the stack only with `make up` and `make down`. A plain `docker compose up` does not read `.env.make.local`.

## Common problems

| Problem | Cause and fix |
|---|---|
| `make up` fails with a port error for 8000 or 5432 | Another program or another stack uses the port. Set `HTTP_PORT` or `DATABASE_PUBLISHED_PORT` in `.env.make.local`, then run `make up` again. |
| A page answers 500: "Could not find the entrypoints file `/app/public/entrypoints.json`. Did the assets get built?" | The web interface is not built. Run `npm ci` and `npm run build`, or keep `npm run dev` running. |
| A page is blank after you stopped `npm run dev` | `public/entrypoints.json` still points to the dev server. Run `npm run build`. |
| Clones, fetches, or the symbol index use old PHP code | The Messenger worker does not restart by itself. Run `make worker-restart` after a PHP change that a background job uses. The FrankenPHP worker that serves the web requests restarts by itself when a file under `src/`, `config/`, or `templates/` changes, and when `npm run build` or `npm run dev` writes `public/entrypoints.json`. |
| **Local directories** says "The local repositories directory contains no git repository." | `LOCAL_REPOSITORIES_HOST_DIR` was not set at the last `make up`, so Docker mounted `./var/local-repositories`. Or the directory has no git repository as a direct child. Set the variable and run `make up` again. |
| The sign-in page says "No sign-in method is configured." | `APP_LOCAL_SIGN_IN` was not set at the last `make up`. Set it and run `make up` again. |
| `make act` says "waiting for the act run in container ..." | Another checkout runs `make act`, and this run starts after it. After an hour it stops waiting. If no act process runs, an earlier run was killed: remove that container with `docker rm -f <container>`. |

For more, read `var/log/dev-<date>.log` and the web profiler at http://localhost:8000/_profiler.
