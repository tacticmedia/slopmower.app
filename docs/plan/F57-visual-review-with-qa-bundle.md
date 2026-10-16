# F57 Visual review with qa-bundle

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-16: integrate tacticmedia/qa-bundle and write journey scenarios that capture the screens, so that he can give visual feedback. The UI features F58 to F60 follow, and each one updates the journeys of the screens it changes.

## Goal

- `make journeys` captures every screen of the SPA in its main states, at five viewports in light and dark.
- Jan reviews the captures at `/_dev/screenshots` on the dev stack, marks a rectangle with a note, and copies the generated prompt into a coding agent.

## Facts

Verified on 2026-10-16:

- `tacticmedia/qa-bundle` 2.0.0 (Packagist, MIT) has two independent halves that share one on-disk contract, the screenshot tree and its JSON sidecars:
  - **Capture.** The npm package `@tacticmedia/qa-capture` 0.1.0 (published 2026-10-16, peer `@playwright/test >=1.40`, Node 20 or later, ES module) exports `./playwright` (a `test` with the fixture `qaScreenshots.capture(label)` and the option `qaScreenshotsOptions`, and `expect`) and `./playwright/global-setup` (empties the tree once per run). Each capture writes one full-page PNG and one sidecar per viewport and colour scheme, by default 390x844, 768x1024, 1024x768, 1920x1080, 1728x1117 in light and dark, under `var/screenshots/<scheme>/<w>x<h>/<orientation>/`. `QA_SCREENSHOTS_DIR` overrides the root. A capture needs a fixed viewport, and a label already captured in the same test is skipped.
  - **Review.** The route `/_dev/screenshots`, dev only. It needs `ext-gd`, `symfony/security-csrf` (a non-dev dependency here through `symfony/security-bundle`), and `symfony/twig-bundle` (installed). It loads its own JavaScript from `/_dev/screenshots/assets/` and Tailwind from a CDN. The app has no content security policy. The bundle prepends the stateless CSRF token id `screenshot-review` and double-submits it itself. Notes and crops go to `var/review`. The prompt template is overridable at `templates/bundles/TacticMediaQaBundle/prompt.txt.twig`.
  - The Flex recipe is in the bundle's `recipe/` directory and is not published to `symfony/recipes-contrib`. The README gives the two files the recipe would write: `config/bundles.php` (dev and test) and `config/routes/qa.yaml` (`when@dev`, resource `@TacticMediaQaBundle/config/routes.php`, type `php`).
- The `php` image has no `gd` extension. `Dockerfile` installs extensions in `frankenphp_base` and `xdebug` in `frankenphp_dev`.
- The `dev` firewall pattern is `^/(_profiler|_wdt|assets|build)/`.
- `assets/app.tsx` wraps the app in `ThemeProvider colorMode="auto"`, so an emulated dark scheme renders the dark theme.
- The UI suite (`playwright.config.ts`, `tests/ui/`) builds the SPA with `tests/ui/vite.config.ts` and serves it with `vite preview` on `UI_PORT`. `tests/ui/support/test.ts` gives the fixtures `api` (auto: mocked `/api/v1`, schema validation, and a failure on an unrouted request or a page error) and `live`. The fakes and builders are in `tests/ui/support/`.
- F50 (peer) changes the same `Dockerfile` (adds `pcntl` and the prod stages) and adds `make prod-check`. Its prod builder runs `composer install --no-dev --no-autoloader --no-scripts` and `composer dump-autoload --classmap-authoritative --no-dev`.
- F55 (peer) changes `playwright.config.ts` (`workers`, `expect.timeout`).

## Decisions

- **Mocked journeys.** Journeys are Playwright Test specs against the mocked API, not full-stack tests: fixed data from the docs/specs fixtures, no database, and the same fakes as the UI suite. Jan chose this.
- **Install:** `composer require --dev tacticmedia/qa-bundle` (2.0.0 or a newer stable) and `npm install --save-dev --save-exact @tacticmedia/qa-capture@0.1.0`. Both go into the verified versions table of the plan README.
- **Configuration**, hand-written because no recipe is published, each file with a one-line header that says so:
  - `config/bundles.php`: `TacticMedia\QaBundle\TacticMediaQaBundle::class => ['dev' => true, 'test' => true]`, unless Flex writes it.
  - `config/routes/qa.yaml`: the `when@dev` import of the README.
  - No `config/packages/qa.yaml`: the defaults `var/screenshots` and `var/review` fit.
  - `config/packages/security.yaml`: the `dev` firewall pattern gets `_dev`.
- **gd only in the dev image:** `install-php-extensions gd` in the `frankenphp_dev` stage. The prod image (F50) must still build: if `composer install --no-dev` refuses the missing `ext-gd` of a dev package, add `--ignore-platform-req=ext-gd` to that one install line, and check that the `platform_check.php` of the `--no-dev` dump does not name gd.
- **Prompt wording:** `templates/bundles/TacticMediaQaBundle/prompt.txt.twig` extends or replaces the bundle template with the project rules: read `AGENTS.md` first; the hard rule that feedback which asks for more than a local fix becomes a `docs/plan/features.md` row and a scope file before code; each screenshot names its journey spec, which `npx playwright test --config playwright.journeys.config.ts <spec>` reproduces; verify with `make journeys` and `make check`. Keep the variables and blocks of the bundle template.
- **Journey runner:**
  - `playwright.journeys.config.ts` imports the default export of `playwright.config.ts` and overrides `testDir: 'tests/journeys'`, `outputDir`, `globalSetup` (`createRequire(import.meta.url).resolve('@tacticmedia/qa-capture/playwright/global-setup')`), and the web server port. So `workers` and the timeouts of F55 apply without a copy.
  - `JOURNEY_PORT`, default 4174, exported by the `Makefile` like `UI_PORT`, so `make journeys` and `make ui` can run at the same time.
  - `tests/journeys/support/test.ts`: `mergeTests()` of the UI suite `test` and the qa-capture `test`, and re-exports `expect`.
  - `make journeys` on the host: `npx playwright install chromium`, the journeys, then it prints the review URL `http://localhost:$(HTTP_PORT)/_dev/screenshots`. It is not a gate and not in `make check` or `qa.yml`. `tsconfig.ui.json` includes `playwright.journeys.config.ts` and `tests/journeys`, so `make front` type-checks them.
- **Journeys**, one spec per area, one `test.describe` per spec, one capture label per screen state. A journey waits for the screen to settle (its content visible, no spinner) before each capture. Every capture runs at 390 px as well, so a layout that breaks there shows in the review.

  | Spec | Captures |
  |---|---|
  | `Repositories.spec.ts` | list with connected repositories and GitHub candidates; empty state |
  | `CodeBrowser.spec.ts` | directory with README; file with a line, a range, and a symbol thread; blame; quick open; the finish-review overlay with drafts |
  | `ReviewRequests.spec.ts` | the requests tab with every status, a suggestion, an outdated request, and a pending plan; a filtered list; an empty filter result; pending drafts |
  | `RequestStages.spec.ts` | one request detail per stage: suggestion pending, open with a plan required, an agent question, a pending proposal with a diff, in progress, ready for review with commits and a pull request, won't do by an agent, verified, reopened, an orphaned location, a closed review, the Changes tab |
  | `Dialogs.spec.ts` | reopen, won't do, unassign, close review, delete review, carry over |
  | `Settings.spec.ts` | API tokens, plan and usage, legal links |

## Depends on

Nothing new. Rebase onto F50 and F55 when they merge first.

## In scope

- `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `config/bundles.php`, `config/routes/qa.yaml`, `config/packages/security.yaml`, `Dockerfile` (dev stage), `Makefile` (`journeys`, `JOURNEY_PORT`), `tsconfig.ui.json`, `playwright.journeys.config.ts`, `tests/journeys/**`, `templates/bundles/TacticMediaQaBundle/prompt.txt.twig`.
- Fakes or builders in `tests/ui/support/` that a journey needs for a state the UI suite does not build yet.
- Docs: `docs/developer-guide.md` section 6 (a row "Journeys" with the visual review flow), the plan README (commands table `make journeys`, the variable `JOURNEY_PORT`, the verified versions, a gotcha: each run empties `var/screenshots`, while notes stay in `var/review`), and `docs/user/privacy.md` only if it lists dev tooling.

## Out of scope

- Full-stack captures of the Twig pages (sign-in, docs, legal pages). Candidate.
- A demo data set for the dev stack (candidate "Demo fixture set for qa-bundle screenshots").
- Journeys in `make check` or CI.

## Acceptance tests

- The journeys are the tests: `make journeys` passes and writes, for each capture label, a PNG and a sidecar for each viewport and scheme.
- No PHP test of the vendor page, and no test of qa-capture behaviour.

## Verification

- `make journeys` on the worktree writes the tree under `var/screenshots/`.
- `curl -s -o /dev/null -w '%{http_code}' http://localhost:<HTTP_PORT>/_dev/screenshots` answers 200, and the page lists the journeys.
- After the rebase onto F50: `make prod-check` is green.
- `make check` is green (it runs `make act`, because `Dockerfile`, `Makefile`, and the lock files are in `ACT_INPUTS`).
