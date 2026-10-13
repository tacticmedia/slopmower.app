# M11 REST foundation and repositories API

Workflow, conventions, and status: [README](README.md).

## Goal

The REST conventions that every endpoint uses, shown on the repository endpoints. The contract is docs/specs/README.md sections 2 to 7 and docs/specs/rest-api.md sections 2 and 4.

## Depends on

M09, M10.

## In scope

- `ApiExceptionListener`, as docs/specs/README.md section 4 says:
  - `DomainError` to its HTTP status and Error body;
  - `#[MapRequestPayload]` and `#[MapQueryString]` validation failures to 422 `invalid_input` with `violations`, each with a dotted `field` path;
  - unparsable JSON or a query value of the wrong type to 400 `invalid_input`. `#[MapQueryString]` fails with 404 by default: set `validationFailedStatusCode: 422` on every use. A query violation without a constraint is a type error, and the listener answers 400 for it;
  - a body that is not JSON to 415, a body above 1 MiB to 413, a wrong method to 405 with an `Allow` header, all `invalid_input`;
  - an unknown route under `/api/v1` to 404 `not_found`;
  - an ID of another tenant to 404 `not_found`;
  - any other exception to 500 `internal_error` with a generic message. The exception is logged.
- The check order of docs/specs/README.md section 4: 401, then 404, then 403, then 400, 413, 415, and 422, then the 409 codes. The Symfony request cycle gives it when:
  - the `access_control` rule of M10 answers 401 on `kernel.request`;
  - path IDs resolve to visible entities in `ValueResolverInterface` implementations, which run before `kernel.controller_arguments`. An invisible ID gives 404;
  - the CSRF listener and `#[IsGranted]` for the actor kind run on `kernel.controller_arguments` above priority -10100. The CSRF listener is not a `kernel.request` listener. In Symfony 8.1, `#[IsGranted]` runs through `ControllerAttributesListener` at -10000;
  - a body-size listener on `kernel.controller_arguments`, also above -10100, answers 413 for a body above 1 MiB. Symfony has no body limit of its own;
  - `#[MapRequestPayload]` and `#[MapQueryString]` map and validate at priority -10100;
  - the services give the 409 codes.
- CSRF on session writes: POST, PUT, PATCH, and DELETE under `/api/v1` with a session pass `SameOriginCsrfTokenManager` with the token ID `api`. A failure gives 403 `forbidden`.
- Serializer normalizers in `src/Serializer/Normalizer`. MCP uses the same normalizers in M15. They emit every schema property, ULIDs in upper case, SHAs in lower case, and times in RFC 3339 UTC with second precision.
- A cursor codec for docs/specs/README.md section 5: base64url JSON of the last sort key plus a hash of the filters. The hash covers every query parameter except `cursor` and `limit`, `sort` included. A cursor used with other filters gives 422 `invalid_input`.
- Endpoints:
  - R2 `GET /api/v1/repositories/candidates`, session only. Query `source`, `q`, `includeConnected`. Local candidates are rescanned on every call. GitHub candidates arrive in M33, so `githubListedAt` and `githubConfigureUrl` are null;
  - R3 `POST /api/v1/repositories`, session only, body `{"source":"local","directory":"<name>"}`. 201 with `Location` for a new repository, 200 for an existing one;
  - R1 `GET /api/v1/repositories`, query `name`, `source`, `limit`, `cursor`. Sorted by name case-insensitively, then by ID;
  - R4 `GET /api/v1/repositories/{repositoryId}`;
  - R5 `POST /api/v1/repositories/{repositoryId}/fetch`: 202 with the `Repository`. It queues a fetch, or the clone again after a failed clone. A queued fetch or clone is reused. A call during a running fetch queues one more fetch. 409 `repository_unavailable` with reason `removed`.
- `composer require --dev opis/json-schema`.
- `SchemaValidator`: loads `docs/specs/schemas/` through a loader that maps the `$id` prefix `https://slopmower.invalid/schemas/v1/` to that directory. The `JsonApiAssertions` trait validates a response against a named schema or `$defs` entry.
- The `CrossTenantCases` registry: one case per `/api/v1` route, with the IDs of another tenant.

## Out of scope

- Reviews: M12.
- R6 repository deletion: M31. R7 blobs: M12.
- The actor-kind check, 403 for the wrong caller kind: M14.

## Files (representative)

- `src/Controller/Api/RepositoryController.php`, `src/Controller/Api/RepositoryCandidateController.php`, `src/Controller/Api/Input/*.php`.
- `src/Serializer/Normalizer/*.php`, `src/Http/ApiExceptionListener.php`, `src/Http/ApiCsrfListener.php`, `src/Http/Cursor.php`.
- `src/Application/Query/RepositoryQuery.php`.
- `tests/Support/Contract/SchemaValidator.php`, `tests/Support/Contract/JsonApiAssertions.php`, `tests/Support/CrossTenant/CrossTenantCases.php`.
- `tests/Functional/Api/*Test.php`, `tests/Functional/Contract/*Test.php`.

## Tasks (ordered)

1. Write the exception listener and the CSRF listener.
2. Write the normalizers and the cursor codec.
3. Write the controllers and their input DTOs.
4. Install `opis/json-schema` and write the contract harness.
5. Add schema assertions to the M10 `MeEndpointTest`.
6. Write the cross-tenant registry and the inventory test.

## Acceptance tests

All are functional tests.

- **F `RepositoryApiTest`** proves R1 to R5. Every response validates against its schema.
  - "Lists the local candidates"
  - "Connecting a candidate returns 201 with a Location header"
  - "Connecting it again returns 200 with the same repository"
  - "Lists repositories and finds one by name"
  - "Gets one repository"
  - "Fetch returns 202 with the sync state fetching"
- **F `ApiErrorModelTest`** proves the error model of docs/specs/README.md section 4.
  - "An anonymous call to a route with a path ID gets 401, not 500"
  - "Bad JSON gives 400 invalid_input"
  - "A body that is not JSON gives 415, and a body above 1 MiB gives 413"
  - "A wrong method gives 405 with an Allow header"
  - "An unknown route under /api/v1 gives 404 not_found"
  - "A validation failure gives 422 with violations"
  - "An unknown ID gives 404 not_found"
  - "A domain error gives its documented status and details"
  - "An invisible ID with an invalid body gives 404, and without a CSRF proof gives 404"
  - "A query value of the wrong type gives 400, and an out-of-range query value gives 422"
  - "An unexpected exception gives 500 internal_error with a generic message"
- **F `ApiCsrfTest`** proves CSRF protection of session writes.
  - "A session POST without same-origin proof gets 403 forbidden"
  - "A session POST with Sec-Fetch-Site same-origin passes"
- **F `CursorTest`** proves pagination.
  - "nextCursor returns the next page and is null on the last page"
  - "A cursor used with other filters gives 422"
- **F `ContractFixturesTest`** proves that the spec fixtures and the schemas agree.
  - "Every docs/specs fixture validates against its schema"
- **F `CrossTenantRouteInventoryTest`** proves tenant isolation for every route.
  - "Fails when an /api/v1 route has no cross-tenant case"
  - "Each case with the other tenant's IDs gets 404 not_found"
  - "List routes return none of the other tenant's items"
- **F `LocalSourcesDisabledApiTest`** proves the disabled state over HTTP.
  - "Candidates report localEnabled false and no local items"
  - "Connecting a local repository gives 422 invalid_input"

## Verification commands

```sh
make test
make lint
make check
```

By hand:

1. Put a git repository under `LOCAL_REPOSITORIES_HOST_DIR` and run `make up`.
2. Sign in locally in the browser. Open `/api/v1/repositories/candidates`.
3. Connect the repository with a `fetch()` call from the browser console, so the same-origin headers are sent.
4. Confirm that the clone exists under `/srv/tenants` in the `php` container, and that the fetch from the read-only mount works.

## Done

Every later route adds a cross-tenant case.
