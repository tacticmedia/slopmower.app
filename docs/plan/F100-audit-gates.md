# F100 Known issue: the npm and Composer audit gates are red

Workflow, conventions, and status: [feature track](features.md). Found on 2026-11-03 during F99. The QA workflow runs `make composer c="audit"` and `npm audit` before `make prod-check`, so each `make act` fails there and skips the image check.

## Goal

- The audit steps of the QA workflow pass.
- Each finding that stays is named with its reason.

## Facts

Verified on 2026-11-03 on `main` at c53dd325.

`composer audit --locked` finds 2 advisories:

| Package | Locked | Severity | Fix | Constraint |
|---|---|---|---|---|
| `phpunit/phpunit` | 13.4.2 | medium, CVE-2026-119193 | 13.4.4 | `^13.4.1` |
| `symfony/mime` | 8.1.7 | medium, CVE-2026-45070 | 8.1.10 | `~8.1.0` |

`npm audit` exits 1 with 6 findings:

| Package | Severity | Path | Fix |
|---|---|---|---|
| `vite` 8.3.1 | high | direct dev dependency | 8.3.5 |
| `vitest` 5.0.2, `@vitest/mocker` | moderate | direct dev dependency | 5.1.0 |
| `fast-uri` 3.1.8 | moderate | `ajv` 8.20.0, dev | `npm audit fix` |
| `deepmerge` 4.3.1, `@primer/react` | low | `@primer/react` 38.40.0, run time | none: every version from 4.2.0 is affected, and 4.3.1 is the latest |

F99 adds `glob` 13.0.6 and `minimatch` 10.2.6, both moderate, through `@sentry/bundler-plugins` 11.10.0, which requires `glob` `^13.0.6`. `glob` 14.0.3 and `minimatch` 11.0.4 are the latest. The plugin globs only the build output, at build time.

## Decisions

- Upgrade the patch versions that fix an advisory inside the constraint: `phpunit/phpunit` 13.4.4, `symfony/mime` 8.1.10, `vite` 8.3.5, `vitest` 5.1.0, and `fast-uri` through `npm audit fix`.
- For a finding without a fix in reach (`deepmerge`; `glob` and `minimatch`), the implementer takes the narrowest rule that works, for example an `overrides` entry with a fixed version that the package accepts, or an `--audit-level`. The rule goes into architecture section 11, and the report names each accepted finding.

## Depends on

F99, which adds `glob` and `minimatch`.

## In scope

- `composer.lock`, `package.json`, `package-lock.json`.
- The audit steps of `.github/workflows/qa.yml`, when the rule needs it.
- The verified versions of `docs/plan/README.md`.

## Out of scope

- `npm audit --prefix symbols`, which passes.

## Acceptance tests

- `make act` passes the two audit steps and `make prod-check`.

## Verification

- `composer audit --locked` and the `npm audit` command of the QA workflow exit 0.
- `make check` is green.
