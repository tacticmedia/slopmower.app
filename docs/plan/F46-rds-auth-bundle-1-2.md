# F46 RDS auth bundle 1.2

Workflow, conventions, and status: [feature track](features.md). Jan asked on 2026-10-09 to upgrade `tacticmedia/rds-auth-bundle` after the release of `tacticmedia/rds-auth-middleware` 1.2.0. This feature closes the "For Jan" item "RDS bundle 1.2.0" from F38.

## Goal

- The application runs `tacticmedia/rds-auth-bundle` 1.2.0 with `tacticmedia/rds-auth-middleware` 1.2.0.
- The accepted database credentials live in a cache pool of their own, as the bundle documentation recommends for one host with web workers and Messenger.

## Facts

- Bundle 1.2.0 requires middleware ^1.2 and `symfony/dependency-injection` ^6.4.4. Middleware 1.2.0 requires `async-aws/core` ^1.28, `async-aws/secrets-manager` ^2.0, and `doctrine/dbal` ^3.10 or ^4.0. The installed versions are 1.1.2 and 1.1.3.
- Middleware 1.2.0 caches the accepted Secrets Manager password without a TTL, instead of for 600 seconds. A rejection replaces the entry. IAM tokens stay cached for 600 seconds.
- `ConfiguredPasswordOutdated` fires when the database accepts the secret password and that password differs from the configured one, also after a rejected cached password. The constructor and the properties do not change. With one pool for all processes, it fires once per rotation.
- IAM mode treats an empty `sslmode` or `port` as unset.
- Bundle 1.2.0 passes the pool as the lazy service `rds_auth.cache_pool`, so pass-through mode never constructs it.
- The bundle uses `cache.app` by default. Here that is `cache.adapter.filesystem` in `framework.cache.directory`, `var/share/<environment>/pools/app`, which the `php` and `worker` containers share through `APP_SHARE_DIR`. A pool on `cache.adapter.filesystem` uses the same directory, under its own namespace.
- The bundle documentation (`docs/credential-cache.md`) recommends a dedicated filesystem pool for one host with web workers and CLI processes: `cache:pool:clear cache.app` and a change of the `cache.app` backend then do not touch the credentials. APCu is not shared with CLI processes, so the worker would not reuse the credential of the web process.

## Decisions

- **Constraint.** `composer.json` requires `tacticmedia/rds-auth-bundle` ^1.2. Composer updates the middleware with `--with-dependencies`.
- **Dedicated pool.** `config/packages/cache.yaml` defines `rds_credentials.cache` on `cache.adapter.filesystem`, and `config/packages/rds_auth.yaml` sets `cache_pool: rds_credentials.cache`. Every other bundle option keeps its default. The pool holds the master password until the next rotation, so it does not share a pool with application data.
- **Listener.** `LogOutdatedDatabasePassword` does not change. One shared pool gives one error per rotation, so no deduplication is necessary.
- D58 in [architecture section 11](../architecture.md#11-decisions) names the pool.

## Depends on

F38 (merged).

## In scope

`composer.json`, `composer.lock`, `config/packages/cache.yaml`, `config/packages/rds_auth.yaml` (new), `docs/architecture.md` (D58), `docs/deployment.md` (credential cache), `docs/plan/features.md`.

## Out of scope

- A Redis or APCu pool.
- Encryption of the pool with `SodiumMarshaller`. It matters when the credentials leave the host, for example in Redis.

## Acceptance tests

- **F, existing, `RdsAuthenticationTest`:** the lock store and the Messenger queue sign in with an IAM token, a raw password with URL-reserved characters signs in, and the listener logs `ConfiguredPasswordOutdated` as an error, all on the 1.2.0 packages.

## Verification

- `composer show tacticmedia/rds-auth-bundle tacticmedia/rds-auth-middleware`: 1.2.0 and 1.2.0.
- `debug:container rds_auth.cache_pool`: a lazy service on `rds_credentials.cache`. `debug:config rds_auth`: `cache_pool: rds_credentials.cache`.
- `make up`: the dev app signs in, and the worker consumes `async`.
- `make check` is green, with `make act`, because `composer.json` and `composer.lock` are in `ACT_INPUTS`. The act job also runs `composer validate` and `composer audit`.
