# M26 Symbol index

Workflow, conventions, and status: [README](README.md).

## Goal

Index the paths and the PHP symbols of each review commit in the background. Architecture section 4, "Reading code and the index".

## Depends on

M12, M18. M24 for live progress.

## In scope

- `composer require nikic/php-parser`, version 5.9.0.
- `IndexedFile` entity: `tenantId`, `repositoryId`, `commitSha`, `path`, `blobSha`, `mode`, `sizeBytes`. The index belongs to a commit inside a repository, because it is code data, not review data. Two reviews at one commit share one set of rows. Unique per tenant, repository, commit, and path.
- `Symbol` entity: `tenantId`, `repositoryId`, `blobSha`, `kind`, `fqn`, `name`, `parentFqn`, `startLine`, `endLine`, `declarationStartLine`, `docStartLine`.
- `IndexedBlob` entity: `tenantId`, `repositoryId`, `blobSha`, `parseError`. It marks each parsed blob, also a blob without symbols. Unique per tenant, repository, and blob.
- `BlobSymbols`, the one parse-on-demand path: it returns the symbols of a blob, or parses the blob and stores the result. It parses only PHP blobs that are renderable: not binary and at most 1 MiB. Any other blob has no symbols. V9, the index job, `AnchorFactory` in M27, and the locator in M29 use it.
- The index writes use `INSERT ... ON CONFLICT DO NOTHING` from an `IndexSql` class. Two jobs at one commit, or V9 and a job, can write the same rows, and a unique key violation in the ORM closes the entity manager. The `IndexedBlob` row and the `Symbol` rows of a blob commit in one transaction. When the `IndexedBlob` insert changes no row, the writer skips the symbols of that blob.
- A migration. The index status fields of `Review` exist from M06.
- A `SymbolExtractor` interface with `#[AutoconfigureTag]`, and `PhpSymbolExtractor`:
  - `ParserFactory::createForNewestSupportedVersion()` plus `NameResolver`;
  - FQNs as architecture section 3 shows, for example `App\Billing\InvoiceTotals::total()`;
  - the range starts at the doc comment when there is one, decision D7. `declarationStartLine` is the first line of the node with its attributes. `docStartLine` is recorded;
  - a parse error gives no symbols.
- An `IndexReview` message, dispatched after the `review.started` transaction commits. The worker's handler:
  - runs `ls-tree -r --long` and inserts the `IndexedFile` rows with the conflict-tolerant inserts of `IndexSql`, also when rows of the commit exist. So a job after a crashed job completes the list;
  - skips blobs that have an `IndexedBlob` row;
  - lists every entry, but reads only regular files, mode `100644` or `100755`. Submodule entries, mode `160000`, have no object in the clone;
  - reads blobs through one `cat-file --batch` process;
  - updates `filesTotal`, `filesDone`, `symbolsTotal`, the state `running`, then `ready` or `failed`, `startedAt`, `finishedAt`, and `error`.
- The handler runs under the Messenger tenant context of M09.
- V5 `GET /api/v1/reviews/{reviewId}/index`: the `IndexStatus`.
- V9 `GET /api/v1/reviews/{reviewId}/symbols?path=`: a `SymbolList`:
  - PHP files: symbols through `BlobSymbols`. A blob without an `IndexedBlob` row is parsed synchronously and stored, so this endpoint never waits for the job;
  - other files: `language: null` and `items: []`;
  - a parse error, from `IndexedBlob.parseError`: `parseError: true` and `items: []`;
  - items sorted by `range.startLine`, then `fqn`;
  - ETag `W/"v1-<blobSha>"`, `Cache-Control: private, no-cache`, 304 on a match.
- The live update `{type: "index", reviewId}`, at most once per second per review, plus the final state.
- `app:index:rebuild`: an operator command.
- A Primer `ProgressBar` in the review layout. It revalidates on `index` updates.

## Out of scope

- Symbols of other languages: [docs/backlog/more-languages.md](../backlog/more-languages.md).

## Files (representative)

- `src/Entity/IndexedFile.php`, `src/Entity/IndexedBlob.php`, `src/Entity/Symbol.php`, `migrations/Version*.php`.
- `src/Index/BlobSymbols.php`, `src/Index/IndexSql.php`.
- `src/Index/*.php`, `src/Message/IndexReview.php`, `src/MessageHandler/IndexReviewHandler.php`, `config/packages/messenger.yaml`.
- `src/Controller/Api/SymbolController.php`, `src/Controller/Api/IndexStatusController.php`, `src/Command/RebuildIndexCommand.php`.
- `assets/review/IndexProgress.tsx`.
- `tests/Fixtures/php/*.php`, `tests/Unit/Index/*Test.php`, `tests/Functional/Index/*Test.php`, `tests/Functional/Api/SymbolsApiTest.php`, `tests/E2E/IndexProgressTest.php`.

## Tasks (ordered)

1. Install the parser. Write the extractor and its fixtures.
2. Write the entities and run `make migration`.
3. Write the message, the handler, and the dispatch after `review.started`.
4. Write V5, V9, and the rebuild command. Add the cross-tenant cases.
5. Publish the `index` updates and build the progress bar.

## Acceptance tests

- **U `PhpSymbolExtractorTest`** proves extraction on PHP 8.5 code.
  - "Fixtures with attributes, enums, property hooks, readonly classes, the pipe operator, and clone-with give the expected table"
  - "The range starts at the doc comment and declarationStartLine includes attributes"
  - "A syntax error gives an empty list"
- **F `IndexReviewHandlerTest`** proves the job.
  - "Blobs shared with an earlier review are skipped"
  - "Two reviews at one commit share the indexed files"
  - "Two index jobs at one commit write each row once"
  - "A job after a crashed job completes the file list"
  - "A blob without symbols and a blob with a parse error are parsed once"
  - "A submodule entry, a binary PHP file, and a PHP file above 1 MiB are listed but not parsed"
  - "The state becomes ready with the progress counters set"
- **F `IndexLiveUpdateTest`** proves the throttle. It uses `mockTime()`.
  - "Index updates go out at most once per second, plus the final state"
- **F `SymbolsApiTest`** proves V5 and V9, with a cross-tenant case.
  - "Returns the symbols of an indexed blob"
  - "Parses a blob that is not indexed yet and stores the result"
  - "A non-PHP file gives language null and no items"
  - "A parse error gives parseError true"
  - "A matching If-None-Match gives 304"
  - "The index endpoint returns the IndexStatus"
- **E `IndexProgressTest`** proves the progress bar.
  - "The progress bar shows and reaches ready"

## Verification commands

```sh
make migration
make db-test
make check
docker compose logs worker
```

## Done

Every new review gets its index in the background, and the symbols endpoint answers without waiting for it.
