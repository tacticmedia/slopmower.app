# M12 Reviews API

Workflow, conventions, and status: [README](README.md).

## Goal

Start revision-specific reviews with a pin ref. List, get, and close them. Browse the pinned commit. The contract is docs/specs/rest-api.md section 5 and R7 in section 4.

## Depends on

M11.

## In scope

- `ReviewCommands::start`, endpoint V1:
  1. If `ref` is a full SHA that is already in the clone, skip the fetch. Otherwise fetch synchronously under the lock, with a 60-second timeout.
  2. Resolve `ref` with `rev-parse --verify --end-of-options <ref>^{commit}`.
  3. Create the pin `refs/holistic-review/reviews/<reviewId>`, decision D26.
  4. Run the `review.started` transaction: actor user, payload `title`, `ref`, `commitSha`. Set the index state to `pending`. M26 adds the index job.
  5. If the transaction fails, delete the pin.
- Start input rules: `ref` defaults to the default branch. `title` defaults to `"<ref> @ <first 12 SHA characters>"`, or to the 12 characters alone when `ref` is a SHA. A `ref` has up to 255 characters, so the ref part is cut until the title has at most 200 characters. `ref` follows the git ref-name rules or is a hex SHA of 7 to 64 characters. A leading `-` is refused.
- `ReviewCommands::close`, endpoint V4, decision D27: `review.closed` with actor user and payload `{note}`. It sets `closedAt`, `closedBy`, and `closeNote`. Closing a closed review gives 409 `review_closed`. M25 adds the deletion of the caller's drafts.
- `ReviewQuery`: the `Review` object with `counts` and `index`, and the `ReviewPage` of V2 with its `repository` object. `Repository.openReviewCount` counts the open reviews.
- `TreeQuery`: `git ls-tree` at the pinned commit, with `dir`, `recursive`, `limit`, and `cursor`.
- `FileQuery`: blob SHA, mode, type, size, `language`, `binary`, `tooLarge` above 1,048,576 bytes, `renderable`, `lineCount`, `symlinkTarget`, `rawUrl`.
- `BlobStream`: streams bytes from `git cat-file` for V8 and R7.
- Endpoints:
  - V1 `POST /api/v1/repositories/{repositoryId}/reviews`: 201 with `Location`. Errors: 409 `repository_unavailable`, 422 `commit_not_found`, 422 `invalid_input`;
  - V2 `GET /api/v1/repositories/{repositoryId}/reviews`: query `state` with `open` as default, `closed`, or `all`; `limit`; `cursor`. Sorted by `createdAt` descending, then ID descending;
  - V3 `GET /api/v1/reviews/{reviewId}`;
  - V4 `POST /api/v1/reviews/{reviewId}/close`: body `{"note": ...}`, optional;
  - V6 `GET /api/v1/reviews/{reviewId}/tree?dir=`;
  - V7 `GET /api/v1/reviews/{reviewId}/file?path=`: ETag `W/"v1-<blobSha>"`, `Cache-Control: private, no-cache`;
  - V8 `GET /api/v1/reviews/{reviewId}/raw?path=`: bytes, strong ETag `"<blobSha>"`, `Cache-Control: private, max-age=31536000, immutable`;
  - R7 `GET /api/v1/repositories/{repositoryId}/blobs/{blobSha}`: bytes, the same headers as V8.
- Byte responses send the headers of R7: `Content-Type` as `text/plain; charset=utf-8` or `application/octet-stream` for binary content, `Content-Length`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox; default-src 'none'`. All cacheable responses send `Vary: Authorization, Cookie`. `If-None-Match` gives 304 with `ETag` and `Cache-Control` and no body.
- Several open reviews per repository, even at one commit, fully separate, decision D8.
- A cross-tenant case for every new route.

## Out of scope

- V5 index status and V9 symbols: M26.
- V11 and V12 viewed files, drafts: M25.
- Deletion: M31.

## Files (representative)

- `src/Application/Query/ReviewQuery.php`, `TreeQuery.php`, `FileQuery.php`, `src/Application/BlobStream.php`.
- `src/Controller/Api/ReviewController.php`, `src/Controller/Api/CodeController.php`, `src/Controller/Api/BlobController.php`.
- `src/Serializer/Normalizer/ReviewNormalizer.php`, `TreeEntryNormalizer.php`, `FileContentNormalizer.php`.
- `tests/Functional/Api/Review*Test.php`, `tests/Functional/Api/Code*Test.php`.

## Tasks (ordered)

1. Implement start and close in `ReviewCommands`. The `review.started` decision checks the repository access inside the transaction. `StartReviewPinRollbackTest` uses that real failure path: the access becomes `removed` between the fetch and the transaction. The test adds a listener for `RepositoryFetched` to the real event dispatcher to cause it. Do not replace container services.
2. Implement the queries and the blob stream.
3. Write the controllers and the normalizers.
4. Add the cross-tenant cases.

## Acceptance tests

All are functional tests. Every JSON response validates against its schema.

- **F `StartReviewTest`** proves V1.
  - "Starts a review at a branch, a tag, and a SHA"
  - "The review pin points at the review commit"
  - "The review.started event has actor user and the default title"
  - "A 255-character ref gives a default title of 200 characters"
  - "A full SHA that is already in the clone starts without a fetch"
  - "An unknown ref gives 422 commit_not_found"
  - "An option-like ref or an ambiguous prefix gives 422 invalid_input"
- **F `StartReviewPinRollbackTest`** proves that a failed start leaves no pin.
  - "When the review.started transaction fails, no pin remains"
- **F `ConcurrentReviewsTest`** proves review separation, decision D8.
  - "Two open reviews of one repository coexist, each with its own pin"
- **F `CloseReviewTest`** proves V4 and the list filter.
  - "Closing stores closedAt, closedBy, and the note"
  - "The list hides closed reviews by default and shows them with state closed or all"
  - "Closing a closed review gives 409 review_closed"
- **F `TreeAndFileTest`** proves the pinned reads.
  - "The pinned bytes stay the same after the source branch moves and a fetch runs"
  - "Binary and too-large files return metadata only"
  - "The file endpoint answers If-None-Match with 304"
- **F `RawAndBlobTest`** proves V8 and R7.
  - "raw returns the bytes of git with nosniff and sandbox headers"
  - "raw answers a matching If-None-Match with 304"
  - "blobs returns a blob of the clone and 404 for an unknown object"
- **F `PathValidationTest`** proves path input rules.
  - "../x, an absolute path, and NUL give 422 invalid_input"
  - "A missing path gives 404 not_found"
  - "A directory path on the file endpoint gives 422"

## Verification commands

```sh
make test
make check
```

## Done

A review always reads the same bytes.
