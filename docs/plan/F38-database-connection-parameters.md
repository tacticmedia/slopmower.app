# F38 Database connection parameters

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-09 to implement `tacticmedia/rds-auth-bundle` correctly: individual parameters for the Doctrine connection instead of a DSN, and Messenger on the database connection of Doctrine. During the work he asked for the locks on that connection too. This feature implements the candidate "Database parameters instead of `DATABASE_URL`" from F28.

## Goal

- The Doctrine connections take the host, the port, the database name, the user, the password, the server version, and the SSL mode from separate environment variables. A raw RDS master password works without encoding.
- Every Messenger transport uses the DoctrineBundle connection `default`, so the RDS authentication middleware wraps the queue too. No variable can point a transport elsewhere.
- The advisory locks use `default` too, so each PHP process has one database session.

## Facts

- `config/packages/doctrine.yaml` builds `default` and `lock` from `%env(resolve:DATABASE_URL)%`. A password in a URL must be percent-encoded, and `resolve:` then reads `%%` as `%` and `%name%` as a container parameter.
- AWS: a rotated RDS master password has 28 characters and at least one punctuation character. Every printable ASCII character except `/`, `'`, `"`, `@`, and space is allowed, so `%`, `#`, and `?` occur. Secret injection (ECS, Elastic Beanstalk, Kubernetes) cannot encode the value.
- DBAL 4.5 `pdo_pgsql` puts the host, the port, the database name, and `sslmode` into the PDO DSN, and gives the user and the password to `PDO` as separate arguments. It adds `sslmode` only when it is set.
- `%env(default::NAME)%` resolves an unset or empty variable to null. The middleware sets `sslmode=require` in IAM mode when `sslmode` is null.
- `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0` in `.env` selects the `async` transport. `failed` is `doctrine://default?queue_name=failed` in `config/packages/messenger.yaml`. The Doctrine transport gets the named connection from the DoctrineBundle registry.
- Compose reads `.env` for interpolation. `compose.override.yaml` publishes the database on `${DATABASE_PORT:-5432}`, and the Makefile, `.github/act.env`, and `bin/act-run` set `DATABASE_PORT` as that host port.
- `mate/config.php` and `tests/Support/Database/LockStores.php` parse `DATABASE_URL` with DBAL's `DsnParser`.
- F28 put the advisory lock store on a second connection `lock`, to keep the database session of its own that `LOCK_DSN` gave it. No code needs that session. `DoctrineDbalPostgreSqlStore` keeps an `InMemoryStore` per connection object, so two locks of one process exclude each other on a shared session. Every lock in `src` uses the non-blocking `acquire()`. `CommandExecutor`, `ReadSnapshotSql`, DBAL `transactional()`, and ORM `wrapInTransaction()` roll back before they rethrow, so no lock release runs in an aborted transaction, which refuses `pg_advisory_unlock`.
- `tacticmedia/rds-auth-bundle` 1.2.0 (2026-10-09) requires `tacticmedia/rds-auth-middleware` ^1.2, which has no release. The latest middleware release is 1.1.3.

## Decisions

- **Variables.** `DATABASE_HOST`, `DATABASE_PORT`, `DATABASE_NAME`, `DATABASE_USER`, `DATABASE_PASSWORD`, `DATABASE_SERVER_VERSION`, and `DATABASE_SSLMODE`. `.env` holds the values of the compose database: `database`, `5432`, `app`, `app`, `'!ChangeMe!'` in single quotes, and `18`. `DATABASE_SSLMODE` is empty.
- **No `resolve:`.** Each variable reaches DoctrineBundle unchanged. `port` uses `int:`, and `sslmode` uses `default::`, so an empty value omits it: libpq then uses `prefer`, and the middleware uses `require` in IAM mode.
- **Constants stay in the configuration.** `driver: pdo_pgsql` and `charset: utf8` do not change per deployment.
- **Locks on `default` (Jan, 2026-10-09).** The connection `lock` goes, and `DoctrineDbalPostgreSqlStore` takes `doctrine.dbal.default_connection`. One session per process saves a connection and, in IAM mode, a token sign-in. The plan README gotchas state the rule for transactions.
- **Published port.** The host port of the compose database becomes `DATABASE_PUBLISHED_PORT`, and its act override `ACT_DATABASE_PUBLISHED_PORT`. Else `.env` `DATABASE_PORT=5432` would also set the published port, and one name would have two meanings. "Published" is the compose term for the host side of a port.
- **Messenger.** `async` and `failed` use `doctrine://default` in `config/packages/messenger.yaml`, both with `auto_setup=0`: the migrations own `messenger_messages`. `MESSENGER_TRANSPORT_DSN` goes.
- **Bundle version.** The bundle stays at 1.1.2. 1.2.0 cannot install until the middleware 1.2.0 is released. The configuration of this feature works with both.
- Recorded as D63 in [architecture section 11](../architecture.md#11-decisions).

## Depends on

F28 (merged).

## In scope

- `.env`, `config/packages/doctrine.yaml`, `config/packages/messenger.yaml`, `config/packages/lock.yaml`.
- `compose.override.yaml`, `Makefile`, `.github/act.env`, `bin/act-run`: `DATABASE_PUBLISHED_PORT`.
- `mate/config.php`: the Mate connection from the same variables.
- `tests/Support/Database/LockStores.php`, `tests/Functional/Doctrine/RdsAuthenticationTest.php`.
- Docs: `README.md`, `docs/plan/README.md` (environment table, gotchas), `docs/deployment.md` (database variables), `docs/architecture.md` (D58, D63), `docs/developer-guide.md` (the `database` row), `docs/backlog/database-hardening.md` (connections table), `docs/plan/features.md`.

## Out of scope

- The bundle upgrade to 1.2.0.
- `sslrootcert`. libpq reads `PGSSLROOTCERT`, so a deployment with `sslmode=verify-full` sets it without an application change.
- The `POSTGRES_*` variables of the compose database.

## Acceptance tests

- **F `RdsAuthenticationTest`:** a role with a password that holds `%`, `%%`, `#`, `?`, `$`, `\`, `&`, `;`, and `=` signs in through `doctrine.dbal.default_connection` with `DATABASE_USER` and `DATABASE_PASSWORD`. A URL or a `resolve:` processor fails this test.
- **F `RdsAuthenticationTest`:** in IAM mode, the `failed` Messenger transport signs in as the IAM user with a token, so Messenger uses the DoctrineBundle connection and its middleware.
- **F `RdsAuthenticationTest`, existing:** the IAM lock test sets `DATABASE_SSLMODE=disable` instead of a URL suffix.
- **F, existing:** `RepositoryLockTest`, `FetchSingleFlightTest`, and `McpSessionIsolationTest` hold a lock in another database session through `LockStores`, so the locks on `default` still exclude other sessions.
- **F, existing:** the IAM lock test proves that the lock store is on a DoctrineBundle connection.

## Verification

- `make up`: `php` and `worker` are healthy, the worker consumes `async`, and the dev app signs in.
- `make mate c="tools:call review-list"` reads the dev database.
- `make console c="debug:config doctrine dbal"` shows parameters and no `url`.
- `make check` is green, with `make act`, because `Makefile`, `compose.override.yaml`, `.github/act.env`, and `bin/act-run` are in `ACT_INPUTS`.
