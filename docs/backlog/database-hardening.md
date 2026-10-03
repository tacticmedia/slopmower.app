# Database hardening

Status: backlog. Not part of the proof of concept. The proof of concept uses one database role and isolates tenants in the application, as [architecture.md](../architecture.md) section 4 describes.

## Why

- The application isolates tenants with a fail-closed tenant context, the Doctrine SQL filter, a raw SQL audit, and the cross-tenant test suite. One missed code path is enough to leak another tenant's data: a DBAL query without `tenant_id`, or a filter that stays suspended too long. Row-level security makes the database refuse foreign rows even when the application has a bug.
- The event log is append-only by design, but only the application enforces it. Database grants make the database enforce it.
- The role that runs migrations owns the tables. An owner bypasses row-level security and can change the schema. The runtime role must therefore be a different role.

## What

### Roles

| Role | Attributes | Owns | Grants | Used by |
|---|---|---|---|---|
| `holistic_owner` | `LOGIN NOSUPERUSER NOBYPASSRLS` | The database, every table, sequence, and function | All | Doctrine Migrations, through the `migrations` connection |
| `holistic_app` | `LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE` | Nothing | `SELECT, INSERT, UPDATE, DELETE` on every table except `review_event` and `doctrine_migration_versions`. Only `SELECT, INSERT` on `review_event`. `USAGE, SELECT` on sequences. `EXECUTE` on the lookup functions. | HTTP requests and the Messenger worker |
| `holistic_maintenance` | `LOGIN NOSUPERUSER NOBYPASSRLS` | Nothing | The grants of `holistic_app`, plus `DELETE` on `review_event` | Review deletion (V13), repository deletion (R6), the tenant purge job, and the projection rebuild |

The Docker `POSTGRES_USER` is a superuser. It is used only by the init script that creates these roles. The application never connects with it.

Default privileges give new tables their grants without extra work in each migration:

```sql
ALTER DEFAULT PRIVILEGES FOR ROLE holistic_owner IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO holistic_app, holistic_maintenance;
ALTER DEFAULT PRIVILEGES FOR ROLE holistic_owner IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO holistic_app, holistic_maintenance;
```

The migration that hardens `review_event` then narrows its grants:

```sql
REVOKE UPDATE, DELETE ON review_event FROM holistic_app;
REVOKE UPDATE ON review_event FROM holistic_maintenance;
REVOKE ALL ON doctrine_migration_versions FROM holistic_app, holistic_maintenance;
```

### Policies

Every table with a `tenant_id` column gets the same policy. `tenant` uses `id` in place of `tenant_id`.

```sql
ALTER TABLE review_event ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON review_event
  USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
  WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
```

- `current_setting('app.tenant_id', true)` returns null when the setting does not exist. `NULLIF` maps an empty value to null. A comparison with null is not true, so no row is visible and an insert fails the `WITH CHECK`. The database fails closed.
- The ULID columns are PostgreSQL `uuid` columns, as Doctrine's `ulid` type stores them.
- `FORCE ROW LEVEL SECURITY` is not set. The lookup functions run as the owner and must see every row. The application never connects as the owner. A boot check and a test enforce this.
- `make:migration` does not generate policies. Each migration that creates a tenant table adds the `ENABLE` and `CREATE POLICY` statements by hand. A catalog test fails when a table with `tenant_id` has no policy.
- Tables without tenant data have no policy: `sessions`, `messenger_messages`, `webhook_delivery`, and `doctrine_migration_versions`.

### Tenantless lookups

The proof of concept suspends the SQL filter in the places of the table below, and in the projection rebuild and the purge. Under row-level security, the runtime role cannot see other tenants' rows at all, so these places use `SECURITY DEFINER` functions owned by `holistic_owner`. Each function returns only IDs. The application then sets the tenant context and loads the entities normally.

| Place | Function | Returns |
|---|---|---|
| GitHub sign-in | `app_find_user_by_github_id(bigint)` | `user_id`, `tenant_id` |
| Local sign-in | `app_find_user_by_local_login(text)` | `user_id`, `tenant_id` |
| Token lookup | `app_find_api_token(text)` with the SHA-256 hex digest | `token_id`, `tenant_id` |
| Session user reload and token owner check | `app_find_signed_in_user(uuid)` | `user_id`, `tenant_id` |
| Webhook router | One function per routing query of `GitHubWebhookConsumer`, for example `app_find_repositories_by_github_id(bigint)` | `tenant_id` and the row ID, one row per tenant |

```sql
CREATE FUNCTION app_find_api_token(p_token_hash text)
  RETURNS TABLE (token_id uuid, tenant_id uuid)
  LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
  AS $$ SELECT id, tenant_id FROM api_token WHERE token_hash = p_token_hash $$;
REVOKE ALL ON FUNCTION app_find_api_token(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_find_api_token(text) TO holistic_app;
```

The first sign-in generates the new tenant ID, sets the tenant context to it, and then inserts the `tenant` and `app_user` rows, so the `WITH CHECK` passes. The projection rebuild and the purge run as `holistic_maintenance` and set the tenant context for each tenant in turn. They find the tenant IDs through `app_list_tenant_ids()`, which only `holistic_maintenance` may execute.

### Connections

| Environment variable | Role | Doctrine connection |
|---|---|---|
| `DATABASE_URL` | `holistic_owner` | `migrations`, selected by `doctrine_migrations.connection: migrations` |
| `DATABASE_RUNTIME_URL` | `holistic_app` | `default`, used by the ORM |
| `DATABASE_MAINTENANCE_URL` | `holistic_maintenance` | `maintenance`, used by review and repository deletion, the purge job, and the rebuild |

### Tenant context in the database: `SET LOCAL app.tenant_id`

- `SET LOCAL` accepts no bind parameters. The application calls `SELECT set_config('app.tenant_id', :tenantId, true)`, which has the same effect and takes a parameter. The value lasts until the end of the transaction, committed or not.
- Every unit of work that touches tenant tables runs in one transaction that starts with this call: each HTTP request after the firewall has set the tenant context, and each Messenger message. A kernel listener and a Messenger middleware open the transaction, set the value, and commit or roll back at the end.
- The write transaction of architecture section 4 runs inside this transaction. Its tenant row lock is held until the outer commit. The post-commit publish of live updates moves to the outer commit.
- Queries outside such a transaction see no tenant rows. In FrankenPHP worker mode the connection stays open between requests, but `SET LOCAL` cannot leak into the next request.

### Lessons from the first setup attempt

- An init script under `/docker-entrypoint-initdb.d/` created the non-owner runtime role. The PostgreSQL image runs these scripts only when the data directory is empty, so a changed script needs a new data volume.
- The Docker `POSTGRES_USER` is a superuser, and superusers bypass row-level security. A test that connects as that user proves nothing about the policies.
- The application then needs two connections: the owner for migrations, and the runtime role for the application.

## Acceptance criteria

Each test extends `KernelTestCase` or `WebTestCase` and runs against PostgreSQL 18 with the three roles.

1. `DatabaseRolesTest`: the runtime connection's `current_user` is `holistic_app`. It is not a superuser, has no `BYPASSRLS`, and owns no table.
2. `RowLevelSecurityCatalogTest`: every table with a `tenant_id` column, and `tenant`, has `relrowsecurity = true` and a policy `tenant_isolation`.
3. `ForeignRowsInvisibleTest`: with the Doctrine filter suspended and the tenant context of tenant A, a raw `SELECT` on every tenant table returns no row of tenant B.
4. `NoTenantContextTest`: without `app.tenant_id`, every tenant table returns zero rows, and an `INSERT` fails with a row-level security error.
5. `ForeignInsertRefusedTest`: an `INSERT` with tenant B's ID in tenant A's context fails.
6. `AppendOnlyEventsTest`: as `holistic_app`, `UPDATE` and `DELETE` on `review_event` fail with SQLSTATE 42501.
7. `TenantContextDoesNotLeakTest`: two requests in one kernel with different tenants each see only their own rows. After each request, `current_setting('app.tenant_id', true)` is null or empty.
8. `MigrationsUseOwnerTest`: the migrations connection runs as `holistic_owner`. The runtime role cannot create a table.
9. `MaintenancePurgeTest`: the purge as `holistic_maintenance` deletes every row of one tenant, including its events. The other tenant is unchanged.
10. `TenantlessLookupTest`: each lookup function returns only IDs, and the runtime role cannot read the looked-up tables without a tenant context.
11. The cross-tenant suite, the gapless-sequence concurrency test, and the isolation audit pass unchanged.
12. The architecture document describes the roles and the policies. Its section 4 and decision D3 describe the hardened database.

## Dependencies

- M01 Local Docker environment: the init script and the compose variables.
- M05 to M07, tenancy persistence, the review data schema, and the event store: the tables, the tenant context, and the write transaction.
- M10 Sign-in and security, M14 API tokens, M32 GitHub sign-in, and M34 GitHub webhooks: the tenantless lookups.
- M31 Data deletion and tenant purge: the maintenance role.
