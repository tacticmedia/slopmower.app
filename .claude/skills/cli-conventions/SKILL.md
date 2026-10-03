---
name: cli-conventions
description: Use when running any command in a Symfony project — console commands, Composer, the local web server, or tests.
version: 1.0.0
updated: 2026-08-28
symfony-versions: ">=6.4"
---

# Symfony CLI conventions

Run the app with `symfony serve -d`; run commands with `symfony console ...` (or
`bin/console` if the Symfony CLI is not available).

When `compose.yaml` defines a `php` service, run every command in that container through
the project wrapper (`make console c="..."`, `make composer c="..."`, `make test`) and do
not start `symfony serve`. Otherwise `command -v symfony` tells you whether the Symfony CLI
is available.

## Prefixes

| Direct                  | With the Symfony CLI      |
|-------------------------|---------------------------|
| `php bin/console <cmd>` | `symfony console <cmd>`   |
| `composer <cmd>`        | `symfony composer <cmd>`  |
| `php -S localhost:8000` | `symfony serve -d`        |
| `php bin/phpunit`       | `symfony php bin/phpunit` |

The prefix matters because the Symfony CLI picks the PHP version configured for the
project, terminates TLS locally, and exports the environment variables of Docker services
such as the database or mailer. Without it the Docker service variables are absent, the
application uses the defaults from `.env`, and the failure looks like a configuration bug.

## Server

```bash
symfony serve -d       # start in the background
symfony server:stop
symfony server:log     # tail the logs
```

## Worth knowing

These apply only when `compose.yaml` has no `php` service. Otherwise they check the host PHP.

```bash
symfony check:requirements    # PHP version and extensions
symfony check:security        # known vulnerabilities in the lock file
symfony var:export --debug    # what the CLI injects into the environment
```
