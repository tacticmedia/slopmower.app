# F28 RDS authentication

Workflow, conventions, and status: [feature track](features.md). Jan's request on 2026-10-08: wire `tacticmedia/rds-auth-bundle` into the application.

## Goal

The application signs in to Amazon RDS with an IAM authentication token, or with the RDS-managed master password that it reads again from Secrets Manager after a rotation. Environment variables select the mode. Every database connection of the application goes through the middleware of the bundle. Without the variables, every connection works as before.

## Facts

- Jan installed `tacticmedia/rds-auth-bundle` 1.1.2 with `tacticmedia/rds-auth-middleware` 1.1.3. Flex registered the bundle. The bundle has no recipe.
- The bundle tags its middleware with `doctrine.middleware` without a `connection` attribute, so the middleware wraps every DoctrineBundle connection. `AWS_REGION` with `RDS_IAM_USERNAME` selects IAM authentication, `AWS_REGION` with `RDS_SECRET_ARN` selects the master-password refresh, and with neither the connection parameters pass through.
- Two connections bypass DoctrineBundle, so the middleware never sees them:
  - Sessions: `PdoSessionHandler` opens its own PDO connection from the parts of `DATABASE_URL` (`config/services.yaml`).
  - Locks: `LOCK_DSN=postgresql+advisory://...` makes `StoreFactory` build `DoctrineDbalPostgreSqlStore` from a string, and that store opens its own `DriverManager` connection (`vendor/symfony/lock/Store/StoreFactory.php:113-116`).
  In IAM mode both fail to sign in. In master-password mode both fail after a rotation.
- `StoreFactory::createStore()` turns a DBAL `Connection` into the table-based `DoctrineDbalStore`, not the advisory store, and it has no case for a store service. `framework.lock` therefore cannot put advisory locks on a DoctrineBundle connection.
- The bundle documentation recommends connection parameters over a DSN: a raw RDS master password can contain `%`, `#`, and `?`, which break a URL.

## Decisions

- **Sessions in files (Jan, 2026-10-08).** `framework.session` uses `session.handler.native_file` with `save_path` `%kernel.project_dir%/var/sessions/%kernel.environment%`. The `PdoSessionHandler` service goes, and a migration drops the `sessions` table. PHP's file handler locks the session file from the start to the save, so the requests of one session stay serialized, and no request writes back a session that logout deleted (M10). A store that several `php` containers share is the backlog item [DynamoDB sessions](../backlog/dynamodb-sessions.md).
- **Locks on a DoctrineBundle connection.** A second connection `lock` has the same `DATABASE_URL` and `dbname_suffix` as `default`, so the advisory locks keep a database session of their own, as `LOCK_DSN` gave them (D39). `framework.lock` is off. `config/services.yaml` defines `DoctrineDbalPostgreSqlStore` on `doctrine.dbal.lock_connection` and `LockFactory` on that store. `LOCK_DSN` goes.
- **Bundle defaults, no configuration file.** Every default fits: the region and the mode from environment variables, the middleware on every connection, `cache.app` for the accepted credentials, and `event_dispatcher` for the event. An APCu pool would need `ext-apcu` in every environment, because the middleware gets its pool also in pass-through mode. The operator can set `rds_auth.cache_pool` where APCu exists.
- **A listener for `ConfiguredPasswordOutdated`.** It logs an error with the event payload, so operations learn that the deployed master password is outdated. Error, not warning: the `prod` `fingers_crossed` handler drops a warning that no error follows.
- **`DATABASE_URL` stays.** In IAM mode the middleware replaces the user and the password. In master-password mode the deployed URL must percent-encode the password. Connection parameters instead of the URL: a candidate in [features.md](features.md#candidates).
- Recorded as D58 in [architecture section 11](../architecture.md#11-decisions).

## Depends on

None.

## In scope

- `config/packages/doctrine.yaml`: the connections `default` and `lock`.
- `config/packages/lock.yaml` and `config/services.yaml`: `framework.lock` off, `LockFactory` on `DoctrineDbalPostgreSqlStore` on the `lock` connection. `LOCK_DSN` leaves `.env`.
- `config/packages/framework.yaml` and `config/services.yaml`: file sessions, no `PdoSessionHandler`. A migration drops `sessions`.
- The `ConfiguredPasswordOutdated` listener.
- The tests that build a lock store in another database session from `LOCK_DSN` build it from `DATABASE_URL` and the database suffix.
- Docs: architecture (D34, D58, the sessions text of section 4, risks, deviations), developer guide, the environment table of the plan README.

## Out of scope

- AWS resources, IAM policies, the IAM database user, the production image, and the deployed environment variables. Operations belong to the operator.
- APCu.
- DynamoDB sessions: backlog.
- Connection parameters instead of `DATABASE_URL`: candidate.

## Acceptance tests

- **Functional:** with `RDS_IAM_USERNAME`, `AWS_REGION`, and static AWS credentials in the environment, a lock from the application's `LockFactory` signs in as the IAM user, and the database refuses the token. This proves that the lock store goes through the middleware.
- **Functional:** a `ConfiguredPasswordOutdated` event through the event dispatcher writes an error record with the secret ARN, the host, the port, the database name, the user, and the SQLSTATE, and no password.
- The lock tests that hold a lock in another database session (`RepositoryLockTest`, `FetchSingleFlightTest`, `McpSessionIsolationTest`) stay green, so the locks are still advisory and cross-session.
- The E2E suite stays green on file sessions.

## Verification

- `make check` is green, including `doctrine:schema:validate`.
- After `make up`, sign in to the dev app, sign out, and sign in again.
