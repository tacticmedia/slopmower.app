# F08 Code-quality leftovers

Workflow, conventions, and status: [feature track](features.md). Hard rule: bugs and issues before features (`AGENTS.md`).

## Goal

Close the code-quality candidates that the developer guide ledger (section 7) and the reports still list. No behaviour change, and no contract change. Every item follows the simplification rule: the result must be smaller and more direct.

## Items

1. **`ReviewCommands` has one consumer in `src`**, `ReviewController`. Inline it per the controller rule of the plan README.
   - Move the test fixtures that start or close a review (`CommandFixtures`, `BrowserFixtures`, `append-events.php`) to the shared code that the controller now holds, or to direct event writes through `CommandExecutor`. The ledger names their cost.
   - If the result is not smaller, keep the class. Record the measured reason in the report and in the ledger.
2. **`GitHubAppJwtFactory` has one consumer**, `InstallationTokenProvider`. Inline it, and turn `GitHubAppJwtTest` into a token-request test of the consumer.
3. **`RequestCommands::submitDrafts()` has one consumer**, `DraftController`. Move it there with the `EntityManager` and `DraftRepository` dependencies that only it uses. `RequestCommands` keeps the event path.
4. **Typed IDs everywhere.** `GitHubCandidate`, `GitHubInstallation`, and `ViewedFile` still map their ID with `UlidType`. Give them ID classes in `Domain\Identity` and map them with `IdentifierType`, as S04 did for the rest.
   - Migrations stay schema-neutral.
   - The ledger row "Typed IDs" then says "Done".
5. **String value objects repeat themselves.** Seven of them repeat `toString()`, and three also repeat `equals()`: `RepoPath`, `CommitSha`, `BlobSha`, `CommitShaPrefix`, `RefName`, `RepositoryName`, and `WontDoReason`. Remove the repetition, for example with one trait. Do it only if the result is smaller and `DomainPurityTest` stays green.
6. **PHPStan sees `mixed` in DQL results.** It has no type descriptor for `IdentifierType` and `StringValueType`, so DQL result shapes fall back to `mixed`, for example in `TenantRepository.php:42`. Add descriptors for phpstan-doctrine, and tighten the shapes that then resolve.
7. **Doctrine deprecations:**
   - `nullable` on the identifier join column `CommitLink::$request`;
   - `Join::WITH` on an arbitrary join (`UserRepository.php:64`);
   - string `orderBy` directions (22 calls).
   - After the fix, the dev log shows no "User Deprecated" entry from our code. Check it with `docker compose exec -T php vendor/bin/mate tools:call monolog-search --term=Deprecated`.
8. **`ext-mbstring` is not required.** Ten `src` files call `mb_*`, but `composer.json` does not require `ext-mbstring`. Add it.
9. **A reftable candidate has no head branch.** A local candidate in the reftable format shows `headBranch` null, because its `HEAD` holds `ref: refs/heads/.invalid` (`src/Source/LocalRepositoryDiscovery.php:135-147`). Read the head branch in a way that works for both formats, for example through `GitRunner` and `git symbolic-ref` in the candidate.

## Out of scope

Behaviour changes, contract changes, and the bug fixes of F06. Run F08 after F06 is merged, because both touch the GitHub and draft code.

## Acceptance tests

- Tests that covered a removed class move to the new home of its behaviour.
- One new test each for items 4 and 9.
- `make check` is green, `doctrine:schema:validate` passes, and `migrations:diff` is empty.

## Verification

`make check`, and the ledger rows updated with "Done in F08."
