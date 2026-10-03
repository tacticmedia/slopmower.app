# Quality-assurance pipeline

Status: backlog. The proof of concept runs the quality gates by hand with `make check`. The plan leaves CI out of scope.

## Why

- `make check` runs only when a developer starts it. A push or a pull request with a failing gate gives no signal.
- The gates need Docker, PostgreSQL, two front-end builds, and Chromium. A developer machine keeps state that hides a missing setup step. A clean runner shows it.

## What

A GitHub Actions workflow that runs on every `push` and every `pull_request`.

- The runner starts the stack with `make up` and `make db-test`, then runs the gates through the `Makefile` targets. CI and a local run then use the same image and the same commands. A gate never gets a second command definition in the workflow.
- Node.js 24 with npm on the runner, for the host-side targets `front` and `ui`.

| Gate | Command |
|---|---|
| php-cs-fixer, dry run | `make cs` |
| PHPStan level 8 | `make stan` |
| `lint:container`, `lint:yaml config`, `lint:twig templates`, `doctrine:schema:validate` | `make lint` |
| PHPUnit unit and functional suites | `make test` |
| `tsc` and Vitest | `make front` |
| Playwright UI suite against the mocked API | `make ui` |
| Full-stack E2E suite | `make e2e` |
| `composer validate --no-check-publish` | `make composer c="validate --no-check-publish"` |
| Composer audit | `make composer c="audit"` |
| npm audit | `npm audit` |

- Cache the Composer cache on `composer.lock`, the npm cache on `package-lock.json`, the Docker build layers on `Dockerfile`, and the Playwright browsers on `PLAYWRIGHT_VERSION` of the `Dockerfile` and `@playwright/test` of `package.json`.
- Upload on failure: `test-failures/` (E2E screenshots), `var/ui/test-results/` (Playwright traces), and `var/log/`.
- Pin each action by SHA with its version as a comment. Look up the current version of each action at the time of the work.
- The `make-ci` skill generates a base workflow with `make:ci`, which needs `symfony/maker-bundle` 1.69 or later. The project has 1.68.0. Update the maker bundle first, or write the workflow by hand. The maker does not generate the Vitest, UI, E2E, and audit jobs.

## Acceptance criteria

1. A push to any branch and a pull request start the workflow.
2. Each gate in the table runs. A deliberate failure in each gate, on a test branch, fails the workflow.
3. The workflow calls the `Makefile` targets. A changed target changes CI without a workflow edit.
4. A failed run has the failure files as downloadable artifacts.
5. A second run with an unchanged lock file and `Dockerfile` restores the caches.
6. The root `README.md` names the workflow next to `make check`.

## Dependencies

- M01 local Docker environment and `Makefile`.
- M34b mocked UI suite and M34c full-stack suite.
- Branch protection on `main` that requires the workflow is a GitHub setting. Jan owns it.
