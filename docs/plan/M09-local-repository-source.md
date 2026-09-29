# M09 Local repository source

Workflow, conventions, and status: [README](README.md).

## Goal

List the repositories under `APP_LOCAL_REPOSITORIES_DIR`, connect one, keep a server clone fetched over `file://`, and answer whether a commit is available. Decision D24.

## Depends on

M07, M08.

## In scope

- `LocalRepositoryDiscovery`:
  - enabled only when the variable is non-empty and the directory is readable. A relative value resolves against the project directory;
  - lists the direct children that contain a `.git` directory or are bare repositories, as `LocalRepositoryCandidate` with `directory`, `bare`, and `headBranch`;
  - skips files, names that start with `.`, `.git` files of worktrees and submodules, and symlinks. The `realpath()` of a child must have `realpath(APP_LOCAL_REPOSITORIES_DIR)` as its parent;
  - sorts by name and returns at most 1,000 items with a `truncated` flag.
- `ConnectLocalRepository`, as docs/specs/rest-api.md R3 says:
  - local repositories disabled gives `invalid_input`. This check runs first;
  - the directory must be in a fresh discovery listing, else `not_found`;
  - an already connected directory returns the existing repository;
  - a new repository gets `syncState = cloning`. After the row commits, a `CloneRepositoryJob` message is dispatched. If the dispatch throws, the repository gets `syncState = failed`, so that R5 queues the clone again.
- The clone handler creates the bare clone at the ID path, fetches, and sets the default branch and `syncState = idle`. A failure sets `syncState = failed` and `lastError`.
- `LocalFetchCommand`: every fetch passes `-c safe.directory=<realpath>` and `-c safe.directory=<realpath>/.git`, and uses `file://<realpath>` as the remote.
- `FetchRepository`, as docs/specs/webhooks-and-live-updates.md section 2 says:
  - single flight: the `RepositoryLock` of M08 makes one fetch run at a time;
  - a new column `Repository.fetchRequested`. An asynchronous trigger runs one atomic conditional update outside any transaction: it sets `fetchRequested = true` and `syncState = fetching` where `fetchRequested` is false and `syncState` is `idle` or `fetching`. It dispatches a `FetchRepositoryJob` only when the update changed a row, and clears the flag again when the dispatch throws. So a queued fetch is reused, and a request during a running fetch queues exactly one more;
  - the job handler clears `fetchRequested` first, then waits for the lock and fetches. A handler that fails later cannot leave the flag set;
  - a synchronous caller has one deadline of 60 seconds for the lock wait and the git fetch together. Symfony Lock has no acquire timeout, and the PostgreSQL store blocks without limit, so the caller polls `acquire(false)` until the deadline from `ClockInterface`. The git fetch gets the remaining time as its timeout. A missed deadline gives `repository_unavailable` with reason `fetch_failed`;
  - after success: set `lastFetchedAt`, clear `lastError`, read `defaultBranch` and `defaultBranchHead`. After that update commits, dispatch the `RepositoryFetched` Symfony event with the previous and the new `defaultBranchHead`. Its listeners write through `CommandExecutor`, which refuses an open transaction;
  - after a failure: set `lastError`. A synchronous caller gets `repository_unavailable` with reason `fetch_failed`.
- Sync state transitions, with the states of `Sync.state` in `docs/specs/schemas/repository.schema.json`:

  | From | Event | To |
  |---|---|---|
  | none | Connect queues the clone job | `cloning` |
  | `cloning` | The clone succeeds | `idle` |
  | `cloning` | The clone fails | `failed`, with `lastError` |
  | `failed` | R5 queues the clone job again | `cloning` |
  | `idle`, `fetching` | An asynchronous fetch is requested, or a synchronous fetch starts | `fetching` |
  | `fetching` | A fetch ends, with success or failure | `idle`, but `fetching` while `fetchRequested` is true |
  | any | Access is removed, M33 and M34 | `unavailable` |

  A synchronous fetch in `cloning`, `failed`, or `unavailable` gives `repository_unavailable` with reason `cloning`, `clone_failed`, or `removed`.
- Messenger tenant context: a `TenantContextMiddleware` on the default bus. At dispatch it adds a `TenantStamp` from `TenantContext` when the envelope has none. At handling it sets the tenant from the stamp and restores the previous context after the message. It clears the entity manager only when no context existed before the message. So a message that the `sync://` transport of `e2e` handles inside an HTTP request keeps the context and the entities of that request. A message without a stamp fails closed, unless its class is on the explicit tenantless list of the middleware. The list is empty at this point.
- `CommitAvailability::ensureAvailable(repository, sha or prefix)`:
  - checks the server clone;
  - if the commit is missing, fetches once and checks again;
  - returns the full SHA or null;
  - an ambiguous prefix gives `invalid_input`.

## Out of scope

- HTTP, GitHub.

## Files (representative)

- `src/Source/LocalRepositoryDiscovery.php`, `LocalRepositoryCandidate.php`, `LocalFetchCommand.php`. Not in `src/Repository`, which Doctrine uses.
- `src/Application/ConnectLocalRepository.php`, `FetchRepository.php`, `CommitAvailability.php`, `src/Application/Event/RepositoryFetched.php`.
- `src/Message/CloneRepositoryJob.php`, `src/Message/FetchRepositoryJob.php`, `src/MessageHandler/*.php`.
- `src/Messenger/TenantStamp.php`, `src/Messenger/TenantContextMiddleware.php`, `config/packages/messenger.yaml`.
- `migrations/Version*.php` for `fetchRequested`.
- `tests/Functional/Source/*Test.php`, `tests/Unit/Source/*Test.php`, `tests/Functional/Messenger/*Test.php`.

## Tasks (ordered)

1. Implement discovery. Treat every directory name as untrusted input.
2. Implement the connect use case and the clone handler.
3. Implement the fetch use case with single flight.
4. Implement the Messenger stamp and middleware, and register the middleware on the default bus.
5. Implement `CommitAvailability`.

## Acceptance tests

- **F `LocalRepositoryDiscoveryTest`** proves the listing rules.
  - "Lists normal and bare repositories, sorted by name"
  - "Ignores files, dot directories, worktree and submodule .git files, and symlinks"
- **F `LocalSourcesDisabledTest`** proves that the feature is off without the variable.
  - "With an empty variable, discovery reports disabled and connect gives invalid_input"
- **F `ConnectLocalRepositoryTest`** proves the connect flow.
  - "Creates the row and the clone at the ID path"
  - "The clone heads equal the source heads"
  - "Detects the default branch"
  - "A second connect returns the existing repository"
  - "A source owned by another user clones and fetches through safe.directory". The container runs as root, so this case gives the source another owner with `chown`.
  - "A failed dispatch of the clone job leaves the repository failed, so R5 clones again"
- **F `ConnectRejectsPathTricksTest`** proves that input cannot reach other directories.
  - "../x, a/b, and absolute paths give invalid_input and write nothing to disk"
  - "A valid name that is not listed gives not_found and writes nothing to disk"
- **F `CloneFailureTest`** proves the failure state.
  - "A source that stops being a repository before the clone sets failed with lastError"
- **F `SourceUntouchedTest`** proves that the server never writes to the source. The container runs as root, so it compares content hashes and modification times.
  - "File hashes and modification times of the source are equal before and after a fetch"
- **U `LocalFetchCommandTest`** proves the fetch arguments.
  - "The arguments include safe.directory, both refspecs, --prune, and a file:// URL"
- **F `FetchSingleFlightTest`** proves single flight.
  - "A fetch requested during a running fetch runs once afterwards and never in parallel"
  - "A second asynchronous request while a fetch is queued dispatches nothing"
  - "A synchronous caller waits for a running fetch, then fetches"
  - "A synchronous caller that misses the 60-second deadline gets repository_unavailable with fetch_failed"
  - "A failed job leaves fetchRequested false, so the next request queues a fetch"
- **F `MessengerTenantContextTest`** proves tenant isolation in the worker.
  - "A stamped message runs under its tenant"
  - "In the worker, the tenant context and the entity manager are cleared after each message"
  - "A message handled inside a request restores the tenant context of that request"
  - "A message without a stamp fails closed"
  - "A message class on the tenantless list runs without a tenant context"
- **F `CommitAvailabilityTest`** proves availability, decision D24.
  - "A new source commit becomes available after one fetch"
  - "An unknown SHA is null after exactly one fetch"
  - "A prefix resolves to the full SHA"
  - "A fetch dispatches RepositoryFetched"
- **F `TenantIsolatedClonePathTest`** proves separate clones per tenant.
  - "Two tenants that connect one directory get two clones"

## Verification commands

```sh
make test
make stan
make check
```

The read-only mount is exercised by hand in M11.

## Done

The local source is fully covered by tests.

## Carried over

From the reports of M05 and M08. Do these in M09. The evidence is in [reports/M08.md](reports/M08.md).

- **file:// percent-decoding (security).** Git percent-decodes the path of a `file://` URL. A directory named `sub%2f..%2ftarget` fetches `target`, which can be another tenant's clone. Build local URLs in one place and escape `%` as `%25`, or `rawurlencode()` each path segment. Apply the same fix to `GitRepositoryBuilder::fileUrl()`. Add a test with a source directory named `sub%2f..%2ftarget`.
- **safe.directory.** Neither `-c safe.directory` nor `GIT_CONFIG_COUNT` reaches the local `git-upload-pack`, and `GIT_CONFIG_GLOBAL=/dev/null` removes the global channel. A source owned by another uid fails with "dubious ownership" on a Linux host. Use a server-owned global config file with only the `safe.directory` entries, passed as `GIT_CONFIG_GLOBAL` for local-source commands, or `--upload-pack="git -c safe.directory=<quoted> -c safe.directory=<quoted>/.git upload-pack"`. Correct the `-c safe.directory` text in architecture section 4 and specs section 7. Test with a source chowned to another uid outside `/app`.
- A detached HEAD in a local source gives `defaultBranch = null` from `BareClone::fetch()`. Keep the stored default branch.
- A pending SHA of a tree, a blob, or an annotated tag still gets a pin ref, and `hasCommit()` is true for an annotated tag's SHA. Check the object type before a link counts as available.
- `TenantContextMiddleware` must call `reset()` before it sets another tenant, because `TenantContext::set()` refuses a different tenant. [M05](reports/M05.md).
- An asynchronous first clone of a large repository may need a larger time budget than the 60 s default of `BareClone::fetch()`.
