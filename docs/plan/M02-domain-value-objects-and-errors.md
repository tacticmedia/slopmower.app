# M02 Domain value objects and errors

Workflow, conventions, and status: [README](README.md).

## Goal

Immutable value objects and domain errors in pure PHP: architecture section 3, the local repository source of decision D24, and review separation of decision D8.

## Depends on

M01.

## In scope

- `composer require symfony/uid symfony/clock`.
- Typed IDs over `Ulid`: `TenantId`, `UserId`, `RepositoryId`, `ReviewId`, `RequestId`, `CommentId`, `EventId`, `BatchId`, `ApiTokenId`, `DraftId`.
- Git and repository values:
  - `CommitSha`: 40 or 64 lower-case hex characters;
  - `CommitShaPrefix`: 7 to 64 hex characters, the input form of `at` and of a review `ref`;
  - `BlobSha`: a full object ID;
  - `RepoPath`, with the rules of `RepoPath` in `docs/specs/schemas/common.schema.json`;
  - `RefName`;
  - `RepositorySource`: `github`, `local`;
  - `RepositoryName`: GitHub `owner/name`, or a local directory name with the rules of `LocalDirectory` in `docs/specs/schemas/repository.schema.json`.
- Anchors:
  - `LineRange`, `SymbolKind`, `SymbolRef`, `AnchorKind`;
  - `ContentSnapshot`;
  - `SnapshotCapture`: LF line endings, trailing whitespace removed, all lines up to 200, else the first 100 and the last 100, 3 context lines on each side, and a `sha256:` hash of the normalized region;
  - `Anchor` with its invariants per kind.
- Actors: `ActorKind`, and `Actor`. A user has a `userId`. An agent has a `userId` and an `agentName`. The system has neither.
- Enums: `RequestStatus`, `Awaiting`, `Resolution`, `CommitLinkState`, `ReviewState` with `open` and `closed`, `RepositoryAccess`, `SyncState`, `IndexState`, `Action`, `LocationStatus`, `OrphanReason`. The value object `Location`.
- Errors:
  - an `ErrorCode` enum equal to the codes in docs/specs: `unauthorized`, `forbidden`, `not_found`, `invalid_input`, `invalid_transition`, `review_closed`, `commits_not_available`, `commit_not_found`, `repository_unavailable`, `internal_error`;
  - a `DomainError` hierarchy with `code(): ErrorCode` and `details(): ?array`. The `details` shape per code is in `docs/specs/schemas/error.schema.json`.

## Out of scope

- Events, Doctrine.

## Files (representative)

- `src/Domain/Identity/*.php`, `src/Domain/Git/*.php`, `src/Domain/Repository/*.php`, `src/Domain/Anchor/*.php`, `src/Domain/Actor/*.php`, `src/Domain/Request/*.php`, `src/Domain/Review/*.php`, `src/Domain/Location/*.php`, `src/Domain/Error/*.php`.
- `tests/Unit/Domain/**/*Test.php`.

## Tasks (ordered)

1. Run `make composer c="require symfony/uid symfony/clock"`.
2. Write each value object as `final readonly` with a private constructor, named constructors, and `equals()`. Invalid input throws `InvalidInput`, which names the field.
3. Typed IDs print upper case and accept any case, as docs/specs says for ULIDs.
4. Write `ErrorCode` and the `DomainError` subclasses:
   - `NotFound`, no details;
   - `InvalidInput` with `violations: list<{field, message}>`;
   - `InvalidTransition` with `requestId`, `action`, `status`, `claimedBy`, `allowedActions`;
   - `ReviewClosed` with `reviewId`, `closedAt`;
   - `CommitsNotAvailable` with `pendingCommits`;
   - `CommitNotFound` with `commit`, the SHA or ref as the caller gave it;
   - `RepositoryUnavailable` with `repositoryId` and `reason`: `cloning`, `clone_failed`, `fetch_failed`, `removed`.
5. Add the purity convention test.

## Acceptance tests

All are unit tests.

- **U `IdentifierTest`** proves that typed IDs keep the ULID format and never mix.
  - "Round-trips its string form"
  - "Prints 26 upper-case Crockford characters and accepts lower-case input"
  - "Rejects an invalid string"
  - "Never equals an ID of another type with the same value"
- **U `CommitShaTest`** and **U `BlobShaTest`** prove the SHA rules of docs/specs.
  - "Accepts 40 and 64 hex characters"
  - "Handles letter case as docs/specs says"
  - "Rejects a prefix that CommitShaPrefix accepts"
- **U `RepoPathTest`** proves that paths cannot escape the tree.
  - "Rejects absolute paths, dot segments, empty segments, a leading dash, and NUL"
  - "Accepts Unicode names"
- **U `RefNameTest`** proves that refs cannot inject options or ref syntax.
  - "Rejects a leading dash, two dots, @{, control characters, .lock, and spaces"
  - "Accepts main, feature/x, v1.2.3, and a SHA"
- **U `RepositoryNameTest`** proves the name rules per source.
  - "Accepts owner/name for GitHub and a plain directory name for local"
  - "Rejects a local name with a slash or a leading dot"
- **U `LineRangeTest`** proves the range invariant.
  - "Rejects a start line below 1 and an end line before the start line"
- **U `SnapshotCaptureTest`** proves the capture rules of architecture section 5.1.
  - "Normalizes CRLF and trailing whitespace"
  - "Keeps 200 lines and cuts 201 lines to the first and last 100 with lineCount 201"
  - "Takes fewer context lines at the file edges"
  - "Produces the known hash for a test vector"
  - "Gives the same hash for a whitespace-only change"
- **U `AnchorTest`** proves the anchor invariants.
  - "Enforces range, symbol, and snapshot rules per kind"
- **U `ActorTest`** proves the actor invariants.
  - "Enforces userId and agentName rules per kind"
- **U `ErrorCodeTest`** proves that the enum matches the contract. It reads `ErrorCode` in `docs/specs/schemas/common.schema.json` with `json_decode`.
  - "The enum equals the error codes in docs/specs"
- **U `DomainErrorDetailsTest`** proves that each error carries its documented details.
  - "Each error returns the details keys of its definition in error.schema.json"
- **U `DomainPurityTest`** proves that the domain has no framework dependency.
  - "src/Domain imports only App\Domain, Symfony\Component\Uid, and PHP core"

## Verification commands

```sh
make test
make stan
make check
```

## Done

Every value object has tests for its accept path and its reject path.
