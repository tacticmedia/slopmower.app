# M05 Tenancy persistence and isolation

Workflow, conventions, and status: [README](README.md).

## Goal

Persist `Tenant` and `User`. Add a tenant context that fails closed, and a Doctrine SQL filter that scopes every ORM query to the tenant.

## Depends on

M01, M02.

## In scope

- `Tenant` entity: `id`, `createdAt`, `eventSeq`.
- `User` entity: `id`, `tenantId`, `signInMethod` with the values `github` and `local`, `githubUserId` as a nullable unique integer, `login`, `name` or null, `avatarUrl` or null, `createdAt`, `lastSignInAt`. `login` is unique only where `sign_in_method = 'local'`, through a partial unique index. A GitHub user is identified by `githubUserId`, because GitHub logins can be renamed and taken again. `User` and `Tenant` are separate tables. There is one user per tenant.
- `TenantOwned` interface.
- `TenantSqlFilter`: enabled by default in `config/packages/doctrine.yaml`. A missing filter parameter throws.
- `TenantContext`: implements `ResetInterface`, so a worker request never inherits a tenant. `set()` sets the filter parameter. `reset()` disables and enables the filter again, which drops the parameter, because DoctrineBundle only calls `clear()` on an open entity manager between requests.
- `TenantFilter::suspend(callable)`: the only way to run tenantless ORM queries.
- Test support:
  - the `ResetDatabase` trait: `TRUNCATE ... RESTART IDENTITY CASCADE` on every table except `doctrine_migration_versions`, decision D36;
  - a `TenantFixtures` builder.
- One database role, decision D3. No row-level security, no grants, no `SET LOCAL`.

## Out of scope

- Sign-in.

## Files (representative)

- `src/Entity/Tenant.php`, `src/Entity/User.php`, `src/Repository/*.php`.
- `src/Tenancy/TenantOwned.php`, `TenantSqlFilter.php`, `TenantContext.php`, `TenantFilter.php`.
- `config/packages/doctrine.yaml`, `migrations/Version*.php`.
- `tests/Support/Database/ResetDatabase.php`, `tests/Support/Fixtures/TenantFixtures.php`, `tests/Functional/Tenancy/*Test.php`.

## Tasks (ordered)

1. Write the entities, the filter, the context, and `TenantFilter`.
2. `TenantFilter::suspend()` uses `FilterCollection::suspend()` and calls `restore()` in a `finally` block. These keep the filter instance and its parameter. `disable()` and `enable()` would create a new instance without the parameter. `FilterCollection::suspend()` throws for a filter that is already suspended, so a nested `TenantFilter::suspend()` runs its callable directly.
3. Run `make migration`, then `make db-test`.
4. Write the test support.

## Acceptance tests

All are functional tests.

- **F `TenantSqlFilterTest`** proves that the filter scopes ORM reads.
  - "With context A, findAll() returns only A's rows"
  - "With context A, find() of a B ID returns null"
- **F `FailClosedTest`** proves that a missing context never leaks data.
  - "A query without a tenant context throws"
- **F `TenantFilterSuspendTest`** proves the one tenantless path.
  - "Inside the callable, results cross tenants"
  - "The filter is on again with the same tenant after the callable, also when the callable throws"
  - "A nested suspend works, and the filter is on again after the outer callable"
- **F `TenantContextResetTest`** proves that workers do not carry a tenant over.
  - "The services resetter clears the tenant context"
  - "After the services resetter runs, an ORM query on a tenant table throws"
- **F `UserIdentityTest`** proves the user keys.
  - "Two GitHub users can hold the same login"
  - "Two local users cannot hold the same login"
- **F `EntityTenancyConventionTest`** proves that every new entity is tenant scoped. It keeps an explicit exception list, which holds only `Tenant` at this point.
  - "Every entity except the listed exceptions implements TenantOwned and maps a non-null tenant_id"

## Verification commands

```sh
make migration
make db-test
make test
make lint
make stan
make check
```

## Done

`TenantFilter::suspend()` is the only tenantless path for ORM queries.
