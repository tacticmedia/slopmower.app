# F31 Directory view reload

Workflow, conventions, and status: [feature track](features.md). A bug of [F25](F25-github-style-code-browser.md), found during F26 on 2026-10-08.

## Goal

A reload or a shared link of a directory view, `/reviews/{id}/tree/{path}`, shows the directory. Today the server has no shell route for that path and answers 404.

## Decisions

- The shell route `shell_review_tree`, `GET /reviews/{id}/tree/{path}` with `path` of one or more characters, next to `shell_review_file`. `docs/specs/rest-api-requests.md` section 7 lists it.
- The route does not capture `/reviews/{id}/tree` without a path: the Code tab is `/reviews/{id}`.

## Depends on

F25 (merged).

## In scope

`src/Controller/ShellController.php`, `docs/specs/rest-api-requests.md` section 7, `tests/Functional/ShellRouteTest.php`, `tests/Functional/Audit/CrossTenantCoverageTest.php`.

## Out of scope

The shell route of the F26 blame view, which F26 adds.

## Acceptance tests

- **F `ShellRouteTest`:** `/reviews/{id}/tree/src/Billing` gives the shell page to a signed-in user; `/reviews/{id}/tree` stays outside the shell routes.
- **F `CrossTenantCoverageTest`:** knows `shell_review_tree` as a shell route.

## Verification

`make check` is green.
