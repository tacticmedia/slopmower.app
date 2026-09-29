# M10 Sign-in and security

Workflow, conventions, and status: [README](README.md).

## Goal

The browser firewall, the dev-only local sign-in of decision D25, an `e2e`-only sign-in for Panther, sessions in PostgreSQL, and `GET /api/v1/me`.

## Depends on

M05.

## In scope

- `TenantUserProvider`: loads a user by ID through `TenantFilter::suspend()` and sets `TenantContext`.
- Firewalls, as docs/specs/README.md section 3 says:
  - `dev` for the profiler and the build assets, without security;
  - `api` for `^/api/v1`: `lazy: true`, and a shared security context with `main`, so a session also authenticates API calls. M14 adds the bearer authenticator to it;
  - `main` for everything else: session, `lazy: true`.
- `TenantContextListener`: runs after the firewall and sets the tenant from the user.
- Entry points:
  - under `/api/v1`: JSON 401 with the Error body, code `unauthorized`, `details: null`, and `WWW-Authenticate: Bearer realm="holistic-review"`;
  - elsewhere: a redirect to `/login`.
- `LocalSignInAuthenticator`, decision D25:
  - configured in the base `main` firewall, not in a `when@dev` block;
  - `supports()` is true only for `POST /login/local` when `kernel.environment` is `dev` and `APP_LOCAL_SIGN_IN` is non-empty;
  - provisions the `Tenant` and the local `User` on first use;
  - logs a warning when the variable is set outside `dev`.
- The route `POST /login/local` exists only when `APP_ENV=dev` and `APP_LOCAL_SIGN_IN` is not empty, as docs/specs/rest-api-requests.md section 7 says: `env: 'dev'` on `#[Route]` plus a route condition on the variable. The `/login` page shows a "Sign in as <login>" button in local sign-in mode. The button posts the stateless CSRF token `authenticate`.
- `E2eSignInController`: `#[When('e2e')]` and `#[Route('/_e2e/sign-in/{login}', env: 'e2e')]`. It provisions and signs in a fixture user.
- `POST /logout` with CSRF.
- `PdoSessionHandler` for `dev` and `e2e`. The `sessions` table comes from Doctrine's schema listener through `make migration`.
- CSRF for API writes: add the token ID `api` to the stateless token IDs with `check_header: true`. Read `SameOriginCsrfTokenManager` to confirm the header and cookie protocol. docs/specs/README.md section 3 requires `Sec-Fetch-Site: same-origin`, or an `Origin` or `Referer` that matches the host. M11 enforces it on session writes.
- A Twig login page.
- A1 `GET /api/v1/me`: `user` with `signInMethod`, `tenantId`, `caller`, `token` as null for sessions, `features` with `localRepositories` and `githubApp` as null until M33, and `links` with `mcpUrl`, `docsUrl`, and `skillUrl`. The shape is `docs/specs/schemas/me.schema.json`.

## Out of scope

- GitHub sign-in: M32.
- API tokens: M14.

## Files (representative)

- `config/packages/security.yaml`, `config/packages/framework.yaml`, `config/packages/csrf.yaml`.
- `src/Security/*.php`, `src/Tenancy/TenantContextListener.php`.
- `src/Controller/SecurityController.php`, `src/Controller/Api/MeController.php`, `src/Controller/E2e/E2eSignInController.php`.
- `templates/security/login.html.twig`, `migrations/Version*.php`.
- `tests/Functional/Security/*Test.php`, `tests/Unit/Security/*Test.php`, `tests/E2E/SignInTest.php`.

## Tasks (ordered)

1. Configure the providers and the firewalls.
2. Write the user provider, the tenant context listener, and the entry points.
3. Write the local sign-in authenticator, its route, and the login page.
4. Write the `e2e` sign-in controller.
5. Configure `PdoSessionHandler` for `dev` and `e2e`. Run `make migration` for the `sessions` table.
6. Configure the CSRF token ID `api`.
7. Write `MeController`.

## Acceptance tests

- **F `LocalSignInGuardTest`** proves that local sign-in cannot work outside `dev`.
  - "In the test environment with APP_LOCAL_SIGN_IN set, POST /login/local gives 404 and /api/v1/me gives 401"
- **U `LocalSignInSupportsTest`** proves the guard in code.
  - "supports() is true only in dev, with a non-empty login, for POST /login/local"
- **F `LocalUserProvisioningTest`** proves provisioning.
  - "The first sign-in creates one tenant and one local user"
  - "A later sign-in finds the same user"
- **F `MeEndpointTest`** proves A1.
  - "An anonymous call gets 401 unauthorized with WWW-Authenticate"
  - "A signed-in user gets the user, the tenant, a user caller, a null token, the features, and the links"
- **F `E2eRouteAbsentTest`** proves that the test sign-in exists only in `e2e`.
  - "The e2e sign-in route gives 404 in the test environment"
- **F `TenantContextFromUserTest`** proves where the tenant comes from.
  - "After sign-in, the filter parameter is the user's tenant"
- **E `SignInTest`** proves real sessions in the `e2e` environment.
  - "After the e2e sign-in and a reload, the user is still signed in and a row exists in sessions"

## Verification commands

```sh
make migration
make db-test
make test
make e2e
make check
```

By hand: run `APP_LOCAL_SIGN_IN=jan make up`, open `http://localhost:8000/login`, sign in, then open `http://localhost:8000/api/v1/me`.

## Done

The tenant always comes from the authenticated user.
