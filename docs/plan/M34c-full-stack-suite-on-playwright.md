# M34c Full-stack suite on Playwright

Workflow, conventions, and status: [README](README.md).

## Goal

The full-stack acceptance tests drive the real E2E site with the `playwright-php/playwright` core library inside PHPUnit. Symfony Panther and the system Chromium are gone.

## Depends on

M34b.

## In scope

- Dockerfile, dev stage:
  - remove the apt packages `chromium` and `chromium-driver`, and the `PANTHER_*` environment variables;
  - add Node.js 24 from the `node:24-trixie-slim` image (copy only `node` and npm, not all of `/usr/local`, where PHP lives);
  - `PLAYWRIGHT_BROWSERS_PATH=/ms-playwright` on a named volume, and the system libraries through `npx playwright install-deps chromium` at the Playwright version that `playwright-php/playwright` resolves.
- `composer require --dev playwright-php/playwright:1.5.0`. Do not install `playwright-php/playwright-symfony`: it routes the browser into the in-process kernel, and these tests need the running site, the Mercure hub, and the worker. Install the matching browser with `vendor/bin/playwright-install chromium` after `composer install` (entrypoint or Makefile).
- `composer remove symfony/panther` and its webdriver dependency. Remove the Panther PHPUnit extension.
- A base for full-stack tests in `tests/Support/E2E/`: a `KernelTestCase` subclass with `Playwright\Testing\PlaywrightTestCaseTrait`, aimed at `http://localhost:8081`, on `app_e2e` through the existing E2E database extension. Fixtures keep coming from services in the PHPUnit process.
- Convert the kept classes: SignIn, VerticalSlice, LiveUpdate, DeleteAccountFlow, ScopedToken, BrowserSmoke. Delete every Panther helper that nothing uses after the conversion.
- Update the convention test that lists the allowed base classes.
- `docs/architecture.md` section 10 and decision rows that name Panther: describe the two suites.

## Out of scope

- The mocked UI suite: M34b.
- `docs/plan/README.md`: the orchestrator edits it. Put the proposed text for "Test conventions", "Known gotchas", and the command table into the report.

## Acceptance tests

- **E** the six converted classes pass with their existing test titles.
- **U** the base-class convention test accepts the Playwright base and rejects `PantherTestCase`.

## Verification commands

```sh
make up
docker compose exec php node --version
make e2e
make check
```

Run `make test` then `make e2e` 10 times in a row and report the pass count.

## Done

No Panther, no system Chromium, the full-stack suite green 10 times in a row, and `make check` green.
