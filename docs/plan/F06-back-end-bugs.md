# F06 Back-end bugs

Workflow, conventions, and status: [feature track](features.md). Hard rule: bugs and issues before features (`AGENTS.md`).

## Goal

Fix the open back-end bugs found in the milestone, simplification, and feature reports, and add the missing tests for rules that have none. Each item was checked against `main` at 26c6def.

## Items

1. **Insert during a delete gives 500.**
   - A D2 draft insert or a V12 viewed-file insert can wait behind a V13 review delete or an R6 repository delete. It then fails with a foreign key violation and answers 500. It must answer 404 `not_found`, as the specs answer for a review that is gone.
   - Evidence: `src/Controller/Api/DraftController.php:90-91` and `src/Controller/Api/ViewedFileController.php:74`. No code handles `ForeignKeyConstraintViolationException`.
2. **Concurrent first sign-in gives 500.**
   - Two simultaneous first sign-ins of one user hit the unique index, and one of them answers 500. This holds for GitHub and for the local sign-in in dev.
   - The loser must read the row the winner wrote and sign in.
   - Evidence: `src/Security/GitHubUserProvider.php:52-58`, `src/Security/LocalUserProvisioner.php:39-45`.
3. **A clone can stay on disk for good.**
   - `removeAccess()` marks the repository removed and then dispatches `DeleteCloneJob`. When that dispatch fails, the clone stays on disk: a later `removeAccess()` returns early and never dispatches the job again.
   - Fix: make the job reachable again, for example by dispatching it on every `removeAccess()` of a removed repository. The job is idempotent.
   - Evidence: `src/Application/FetchRepository.php:175-179`.
4. **Missing purge check on the repository webhook.**
   - `GitHubRepositoryHandler` takes the tenant lock without the `TenantRepository::lockActive()` check that `GitHubInstallationHandler::locked()` does. A job queued before a tenant purge can update a repository and publish during the purge.
   - Add the check. Then let the four places that repeat the tenant lock block share one shape, if the result is smaller. The four places are `InstallationSync.php:104`, `GitHubRepositoryHandler.php:36`, `GitHubInstallationHandler.php:210`, and `RepositoryController.php:136`.
5. **The webhook parser accepts a list body.**
   - `GitHubRequestParser` accepts a signed JSON list body and passes it on as the payload. It must refuse any body that is not a JSON object, as for an unparsable body.
   - Evidence: `src/Webhook/GitHubRequestParser.php:54`.
6. **Thread reads scan without an index.** `review_event.request_ids` has no GIN index. Add it through `make migration`, and confirm that the thread query's operator can use it (`EXPLAIN`).
7. **Missing tests for existing rules:**
   - `AccessRemovedWebhookTest`: after an access removal, the clone, the index rows, and the location-cache rows of the repository are deleted (D22).
   - After a failed GitHub callback, `/login` shows the error. `OAuthStateTest` asserts only the redirect.

## Out of scope

The front end (F07), code-quality refactors (F08), and the dev environment and docs (F09).

## Acceptance tests

- One functional test per bug 1 to 5 that fails before the fix. For races, force the ordering with the database or a lock, as `tests/Functional/Deletion/` does.
- The two tests of item 7.
- `make check` is green, and `doctrine:schema:validate` passes.

## Verification

`make check`, and for item 6 the `EXPLAIN` output in the report.
