# M08 Git plumbing

Workflow, conventions, and status: [README](README.md).

## Goal

Safe git primitives for the server's bare clones: run, fetch, pin, read.

## Depends on

M01, M02.

## In scope

- `composer require symfony/lock`. `LOCK_DSN` in `.env` uses PostgreSQL advisory locks, decision D39, with the host `database`, as `DATABASE_URL` does. Confirm the DSN form in the Lock component documentation.
- `GitRunner`:
  - argument arrays only;
  - environment `GIT_TERMINAL_PROMPT=0`, `GIT_CONFIG_NOSYSTEM=1`, `GIT_CONFIG_GLOBAL=/dev/null`, `LC_ALL=C`;
  - timeouts;
  - secrets only as environment values through `GIT_CONFIG_COUNT`, `GIT_CONFIG_KEY_<n>`, and `GIT_CONFIG_VALUE_<n>`, decision D38. They are redacted from exception messages and logs.
- `TenantDataPaths`: `<APP_TENANT_DATA_DIR>/<tenantId>/repositories/<repositoryId>.git`. `APP_TENANT_DATA_DIR` is the `<data>/tenants` directory of docs/specs/README.md section 7. Paths come only from typed IDs, and the class checks that each path stays under the root. A relative `APP_TENANT_DATA_DIR` resolves against `%kernel.project_dir%`, never against the working directory, which differs between the web server and the CLI.
- `BareClone`:
  - `init --bare`;
  - `fetch --no-auto-maintenance <url> --prune +refs/heads/*:refs/heads/* +refs/tags/*:refs/tags/*`, never `--mirror`. `--no-auto-maintenance` keeps git from starting a background process in the clone;
  - then a second fetch with the refspecs `<sha>:refs/holistic-review/commits/<sha>` for the pending linked SHAs. One unknown SHA fails a whole `git fetch`: git exits 128 with `not our ref` and writes no ref. After a failure, fetch one SHA per command and ignore the failures;
  - `HEAD` set to the default branch from `ls-remote --symref`.
- `PinRefs`, decision D26:
  - `refs/holistic-review/reviews/<reviewId>` and `refs/holistic-review/commits/<sha>`;
  - created with `update-ref` and the zero OID as the old value, so an existing pin never moves;
  - delete and list.
- `GitReader`:
  - `resolveCommit` through `rev-parse --verify --end-of-options <ref>^{commit}`;
  - `hasCommit` through `cat-file -e <sha>^{commit}`;
  - `resolvePrefix`, `lsTree`, `lsTreeRecursive` with `--long`;
  - `blobAt`, `readBlob` through one `cat-file --batch` process;
  - binary detection: a NUL byte in the first 8,000 bytes;
  - `lineCount`.
- `RepositoryLock`: one lock per repository through `LockFactory`, with the key `fetch:<repositoryId>` that docs/specs names. Fetch, clone, and clone deletion hold it. Pin writes and reads do not.

## Out of scope

- Repository sources: M09 and M33.

## Files (representative)

- `src/Git/*.php`, `config/packages/lock.yaml`.
- `tests/Support/Git/GitRepositoryBuilder.php`, `tests/Support/Filesystem/TemporaryDirectories.php`.
- `tests/Functional/Git/*Test.php`, `tests/Unit/Git/*Test.php`.

## Tasks (ordered)

1. Install `symfony/lock` and configure `LOCK_DSN`.
2. Implement the primitives.
3. Write the test builders: `GitRepositoryBuilder` creates source repositories with commits, branches, and tags under `var/test/`. `TemporaryDirectories` removes them after each test.
4. Write the convention test that keeps processes inside `src/Git`.

## Acceptance tests

- **F `BareCloneFetchTest`** proves the refspecs.
  - "After a fetch only refs/heads, refs/tags, and refs/holistic-review exist"
- **F `PinRefsSurvivePruneTest`** proves that reviewed commits survive force-pushes and garbage collection. A branch is deleted and another is force-pushed at the source.
  - "fetch --prune changes the heads but keeps the pins"
  - "git gc --prune=now keeps the pinned commits readable"
- **F `PinRefsTest`** proves the pin rules.
  - "Creating a pin twice is idempotent"
  - "Moving a pin to another commit is refused"
  - "Deleting a pin removes the ref"
- **F `PendingShaFetchTest`** proves the fetch of pending SHAs by ID over `file://`. Record in the report which SHAs a by-ID fetch can get: a commit on a branch, and a commit reachable only from a ref outside `refs/heads`.
  - "A pending SHA that the source has lands in refs/holistic-review/commits"
  - "An unknown pending SHA does not stop the heads or the other pending SHAs"
- **F `GitReaderTest`** proves the reads.
  - "Lists tree entries at a commit"
  - "Returns blob bytes equal to the source file"
  - "Detects a binary file"
  - "Counts lines with and without a trailing newline"
  - "Resolves a branch, a tag, a SHA, and a prefix"
- **U `RefInjectionTest`** proves that refs never become git options.
  - "Rejects --upload-pack=x, -h, a..b, and HEAD@{1} before git runs"
- **F `UnknownRefTest`** proves the error for an unknown ref.
  - "An unknown ref gives commit_not_found"
- **U `TenantDataPathsTest`** proves that paths come from IDs only.
  - "Builds the clone path from the tenant ID and the repository ID"
  - "Resolves a relative root against the project directory"
- **F `GitRunnerRedactionTest`** proves that secrets never leak.
  - "A secret in the environment never appears in exception messages or logs"
- **F `RepositoryLockTest`** proves the lock scope.
  - "The same repository blocks a second holder"
  - "Different repositories do not block each other"
- **U `NoProcessOutsideGitTest`** proves that all git access goes through `GitRunner`.
  - "No file in src outside src/Git creates a Process"

## Verification commands

```sh
make test
make stan
make check
```

## Done

All git access goes through `GitRunner`.
