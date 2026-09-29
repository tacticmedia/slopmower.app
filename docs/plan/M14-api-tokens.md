# M14 API tokens

Workflow, conventions, and status: [README](README.md).

## Goal

Per-user API tokens authenticate agents as actor `agent`, decision D30. The contract is docs/specs/README.md section 3 and docs/specs/rest-api-requests.md section 6.

## Depends on

M11.

## In scope

- `ApiToken` entity: `user`, `name`, `tokenHash` unique, `prefix`, `repository` where null means all repositories of the tenant, `expiresAt` or null, `createdAt`, `lastUsedAt` or null, `revokedAt` or null.
  - The name is the agent name on every event made with the token. It follows `TokenName` in `docs/specs/schemas/api-token.schema.json`.
  - `state` is derived: `active`, `expired`, or `revoked`.
- `ApiTokenIssuer`:
  - the secret is `hr_` plus 32 random bytes in base64url without padding, 46 characters in total;
  - the server stores `hash('sha256', secret)` as lower-case hex and a `prefix` of the first 9 characters. SHA-256 is enough for a random secret of this length;
  - the plaintext is returned once, in the create response;
  - `expiresAt` is null or between now plus 1 minute and now plus 366 days;
  - at most 50 active tokens per user.
- `ApiTokenHandler`, an `AccessTokenHandlerInterface`:
  - a token that does not match `^hr_[A-Za-z0-9_-]{43}$` gets 401 without a database lookup;
  - a tenantless hash lookup through `TenantFilter::suspend()`;
  - unknown, revoked, and expired tokens get 401. A token is expired when `expiresAt <= now`;
  - `lastUsedAt` is updated at most once per 60 seconds;
  - it returns an `AgentUser` with the agent name and an `AccessScope`, and sets the tenant context.
- The firewall `api_bearer`, before `api`, with the `access_token` authenticator and this handler:
  - the request matcher service `BearerApiRequestMatcher` selects it for `^/api/v1` with an `Authorization: Bearer` header. So the bearer token wins over a session cookie;
  - `stateless: true`, so a bearer request never starts a session, and the response has no `Set-Cookie`. On a stateful firewall, `ContextListener::onKernelResponse` would write the token into the session;
  - every 401 carries `WWW-Authenticate: Bearer realm="holistic-review"`, plus `, error="invalid_token"` when a token was sent.
- `BearerAuthenticationFailure`: the `failure_handler` of `api_bearer`. M15 also makes it the `failure_handler` and the `entry_point` of `mcp`. It answers 401 with the Error body, code `unauthorized`, and the `WWW-Authenticate` header of docs/specs/README.md section 3. Without it, `AccessTokenAuthenticator` answers with an empty body and its own header format, and it has no entry point.
- `CurrentActor`: actor `user` for a session, actor `agent` with `userId` and `agentName` for a token.
- The actor-kind rule of docs/specs/README.md section 3: a session-only endpoint called with a token, or a bearer-only endpoint called with a session, gets 403 `forbidden`. A 404 for an invisible ID comes first.
- `AccessScope` in every query: a token scoped to one repository sees only that repository and its reviews, requests, drafts, and blobs. Every other ID answers 404 `not_found`.
- A1 `GET /api/v1/me` with a bearer token returns the agent caller and the `token` object.
- Endpoints, all session only:
  - T1 `GET /api/v1/tokens`: active, expired, and revoked tokens, sorted by `createdAt` descending;
  - T2 `POST /api/v1/tokens`: body `{name, repositoryId, expiresAt}` with optional `repositoryId` and `expiresAt`. 201 with `ApiTokenWithSecret`. An unknown `repositoryId` gives 404;
  - T3 `DELETE /api/v1/tokens/{tokenId}`: sets `revokedAt` and returns 204. Revoking again returns 204. The row stays, so its events keep the agent name.

## Out of scope

- MCP: M15.
- The settings screen: M19.

## Files (representative)

- `src/Entity/ApiToken.php`, `src/Repository/ApiTokenRepository.php`.
- `src/Security/ApiTokenHandler.php`, `AgentUser.php`, `CurrentActor.php`, `AccessScope.php`, `BearerApiRequestMatcher.php`, `BearerAuthenticationFailure.php`.
- `src/Application/ApiTokenIssuer.php`, `src/Controller/Api/TokenController.php`.
- `config/packages/security.yaml`, `migrations/Version*.php`.
- `tests/Unit/Security/*Test.php`, `tests/Functional/Security/*Test.php`, `tests/Functional/Api/Token*Test.php`.

## Tasks (ordered)

1. Write the entity and run `make migration`.
2. Write the issuer and the handler.
3. Configure the `api_bearer` firewall and its request matcher.
4. Enforce the actor-kind rule and `AccessScope` in the existing controllers and queries.
5. Write `TokenController` and extend `MeController`.
6. Add the cross-tenant cases.

## Acceptance tests

- **U `TokenFormatTest`** proves the secret format.
  - "A secret matches ^hr_[A-Za-z0-9_-]{43}$"
  - "1,000 secrets are unique"
  - "The stored hash is the SHA-256 hex of the secret and the prefix is its first 9 characters"
- **F `TokenStorageTest`** proves that the plaintext is never stored.
  - "No column holds the plaintext secret"
- **F `BearerAuthenticationTest`** proves bearer authentication.
  - "A valid token makes the caller an agent with the token name"
  - "Malformed, unknown, revoked, and expired tokens get 401 with error invalid_token"
  - "A 401 has the Error body and exactly the WWW-Authenticate header of docs/specs"
  - "A bearer response has no Set-Cookie header"
  - "The bearer token wins over a session cookie"
  - "A bearer call with a session cookie leaves the session unchanged"
  - "The secret never appears in var/log"
- **F `TokenScopeTest`** proves repository scope.
  - "A scoped token lists only its repository"
  - "A scoped token gets 404 for another repository and its reviews"
  - "/api/v1/me shows the scope of the token"
- **F `TokenManagementTest`** proves T1 to T3.
  - "The create response shows the secret once"
  - "The list shows neither the secret nor the hash"
  - "Revoke returns 204, also a second time"
  - "An expiry outside one minute to 366 days gives 422"
  - "A 51st active token gives 422"
- **F `ActorKindTest`** proves the actor-kind rule.
  - "A session-only endpoint called with a token gives 403 forbidden"
- **F `LastUsedThrottleTest`** proves the throttle. It uses `mockTime()`.
  - "lastUsedAt changes at most once per 60 seconds"
- **F `CrossTenantTokenTest`** proves tenant isolation of tokens.
  - "A token of tenant A never reads data of tenant B"
  - "Revoking a token ID of tenant B gives 404"

## Verification commands

```sh
make migration
make db-test
make test
make check
```

## Done

The agent identity comes only from the token.
