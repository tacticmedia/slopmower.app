# DynamoDB sessions

Status: backlog. The application stores browser sessions in files under `var/sessions/<environment>`, by decision D58 (F28).

## Why

- A file session is local to one `php` container. With two or more `php` containers behind a load balancer, a request can reach a container that does not have the session, and the user is signed out.
- The session must not depend on the database credentials: F28 moved sessions out of PostgreSQL so that every database connection goes through the RDS authentication middleware.

## What

- A session handler that stores sessions in an Amazon DynamoDB table, with the item TTL for expiry.
- Check what exists before the work starts: Symfony's session handlers and `SessionHandlerFactory` DSNs, and the AsyncAws DynamoDB client `async-aws/dynamo-db`. `tacticmedia/rds-auth-middleware` already brings in `async-aws/core`.
- The handler serializes the requests of one session, as the file handler does with its file lock, so no request writes back a session that logout deleted (M10).
- Keep the file handler as the default for one container, selected by configuration.

## Acceptance criteria

1. A session written through one kernel is read through a second kernel that shares the table.
2. Logout during a concurrent request leaves no session behind.
3. An expired session is not read, and the TTL removes its item.
4. The E2E suite passes with both handlers.

## Dependencies

- F28 RDS authentication.
- Operations: more than one `php` container, and the DynamoDB table with its TTL attribute. Operations are outside these documents.
