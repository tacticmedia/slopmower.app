# M06 Review data schema

Workflow, conventions, and status: [README](README.md).

## Goal

Doctrine mappings and a migration for repositories, reviews, requests, comments, commit links, and the event log.

## Depends on

M04, M05.

## In scope

- `Repository`:
  - `source`, `name`, `localDirectory` or null, `githubRepositoryId` or null;
  - `defaultBranch` or null, `defaultBranchHead` or null;
  - `syncState` with `cloning`, `failed`, `idle`, `fetching`, `unavailable`; `lastFetchedAt` or null; `lastError` or null;
  - `access` with `active` and `removed`; `createdAt`;
  - unique `(tenant_id, source, name)`.
  The GitHub installation link arrives in M33.
- `Review`:
  - `repository`, `title`, `ref`, `commitSha`, `state` with `open` and `closed`;
  - `startedBy`, `createdAt`, `closedAt` or null, `closedBy` or null, `closeNote` or null;
  - `lastEventSeq`;
  - the index status fields: `indexState`, `filesTotal` or null, `filesDone`, `symbolsTotal`, `indexStartedAt` or null, `indexFinishedAt` or null, `indexError` or null. They exist from here on, so that the `Review` response of M12 is complete. M26 fills them.
- `ChangeRequest`: every field the reducer needs, the anchor as `jsonb`, `Actor` as an embeddable for `author` and `claimedBy`, `claimedAt`, `statusBeforeClaim`, `awaiting`, `resolution`, `lastEventSeq`, `updatedAt`.
- `Comment`.
- `CommitLink`: `state`, `linkedBy`, `linkedAt`, `note`, `foundAt`, `subject`, `committedAt`. Unique per request and commit. `state`, `foundAt`, `subject`, and `committedAt` come from the `commit.linked` or `commit.found` payload, so a rebuild needs no clone.
- `ReviewEvent`:
  - a read-only entity;
  - a `requestIds` column as a `jsonb` array, copied from the payload, so that thread and updates queries select events by request;
  - unique `(tenant_id, seq)`; index `(tenant_id, review_id, seq)`.
- IDs as Doctrine `ulid` columns with typed-ID getters.
- Custom DBAL types only for `CommitSha`, `BlobSha`, `RepoPath`, and `Anchor`. `Anchor` is `jsonb` through `AnchorCodec`. Enums use `enumType`.
- `RequestStateMapper`: maps between `ChangeRequest` and the domain `RequestState`.

## Out of scope

- `Draft`, `ViewedFile`, `ApiToken`, `GitHubInstallation`.
- The index tables and the location cache.

## Files (representative)

- `src/Entity/*.php`, `src/Doctrine/Type/*.php`, `src/Doctrine/Embeddable/ActorEmbeddable.php`, `src/Repository/*.php`.
- `src/Projection/RequestStateMapper.php`.
- `migrations/Version*.php`.
- `tests/Functional/Persistence/*Test.php`, `tests/Unit/Doctrine/*Test.php`.

## Tasks (ordered)

1. Write the mappings, the DBAL types, the embeddable, and the mapper.
2. Run `make migration`. Read the generated SQL. Apply it with `make console c="doctrine:migrations:migrate --no-interaction"` and `make db-test`.

## Acceptance tests

- **F `EntityRoundTripTest`** proves that every entity persists without loss.
  - "Each entity reloads with equal values"
  - "Anchors with a symbol and a truncated snapshot reload unchanged"
  - "All actor kinds reload unchanged"
- **F `RequestStateMapperTest`** proves that projections hold the domain state.
  - "States from the M04 scenarios survive the round trip"
- **F `UniqueConstraintsTest`** proves the database guards.
  - "A duplicate commit link fails"
  - "A duplicate tenant and seq pair fails"
- **U `CustomTypeTest`** proves the DBAL types.
  - "Converts valid values in both directions"
  - "Rejects an invalid database value"

## Verification commands

```sh
make migration
make db-test
make test
make lint
make check
```

## Done

`doctrine:schema:validate` passes, and the M05 convention test passes for every new entity.
