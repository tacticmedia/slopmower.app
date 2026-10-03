# M34b Mocked UI suite

Workflow, conventions, and status: [README](README.md).

## Goal

Front-end behaviour tests run in Playwright Test (TypeScript) against a fully mocked API. They need no PHP, no database, and no git. The code view no longer goes blank when an annotation changes before the first highlighted rows arrive.

## Depends on

M00 to M34. The E2E reset fix and the `app_e2e` database (commit `test: own e2e database and reset fix`).

## In scope

- `@playwright/test` at its latest stable version (check npm), 1.63.0, `playwright.config.ts`, and specs in `tests/ui/*.spec.ts`.
- The SPA served without Symfony: a Vite entry for UI tests that mounts the app, `tests/ui/vite.config.ts` and `tests/ui/app/index.html`. The Playwright `webServer` option builds it and starts `vite preview`.
- API mocking: one dispatcher per test, `MockApi` in `tests/ui/support/mockApi.ts`, on `page.route` for `**/api/v1/**` and `**/logout`. Fixture builders in `tests/ui/support/builders.ts` start from `docs/specs/fixtures/` and override fields. Every mocked body validates against `docs/specs/schemas/` (JSON Schema 2020-12, Ajv in `tests/ui/support/schemas.ts`) before it is served. Builders are typed with `assets/api/types/`.
- Live updates: a fake `EventSource` through `addInitScript`, and a helper that pushes a Mercure update into the page, both in `tests/ui/support/live.ts`.
- Port the browser-behaviour tests of these Panther classes, one spec per class, with the same test titles: ApiErrorDisplay, BatchReview, BinaryFile, ChangesTab, ClosedRequestReadOnly, CodeView, DeleteRepositoryFlow, DeleteReviewFlow, ExactRangeBecomesSymbol, FileComment, GitHubCandidates, IndexProgress, KeyboardCommentOnLines, KeyboardOnlyCommenting, LargeFile, LineComment, OutdatedLabel, RangeComment, Reopen, ReplyAndMarkdown, Repositories, ReviewLifecycle, Shell, TokenSettings, Unassign, Viewed, WontDo. Delete each ported PHP class, and each PHP test helper that only those classes use. The helper files `BrowserFixtures.php`, `CodeComments.php`, and `RequestScreens.php` in `tests/Support/E2E/` stay for the kept classes, without those helpers. A test that asserts a server-side effect asserts the request the page sent instead.
- The blank code view: in `@pierre/diffs` 1.5.1, an annotation change before the first highlighted rows arrive leaves the view blank for good. Fix it in `assets/code/CodeFile.tsx`. Add a regression spec, `tests/ui/EarlyAnnotation.spec.ts`, with 4x CPU throttling and 100 ms network latency through a CDP session. It also holds back the PHP grammar chunk of the worker until the annotation has changed. It fails without the fix.
- `npm run test:ui`, and a `make ui` target that runs it on the host when `package.json` exists. `make check` runs `ui` after `front`.

## Out of scope

- The full-stack suite, the Docker image, and the removal of Panther: M34c.
- The kept full-stack Panther classes: SignIn, VerticalSlice, LiveUpdate, DeleteAccountFlow, ScopedToken, BrowserSmoke. They stay as they are until M34c.

## Acceptance tests

- **UI** each ported class has a spec, and each of its test titles exists.
- **UI** "The code view renders after an early annotation change on a slow client" fails on the code before the fix and passes after it.
- **UI** "Every mocked response validates against its schema", in `tests/ui/MockedResponses.spec.ts`: a deliberately invalid builder output fails the test.

## Verification commands

```sh
npm run test:ui
make check
```

Run `npm run test:ui` 10 times in a row and report the pass count.

## Done

The ported Panther classes are gone, the UI suite is green 10 times in a row, and `make check` is green.
