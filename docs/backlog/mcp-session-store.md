# Shared MCP session store

Status: backlog. The proof of concept uses the `file` session store of `symfony/mcp-bundle` under `var/mcp-sessions`, by decision D40.

## Why

- MCP clients of the handshake era, up to protocol revision 2025-11-25, keep a session. The `file` store is local to one `php` container.
- With two or more `php` containers behind a load balancer, a request can reach a container that does not know the session, and the client fails.
- Clients of the 2026-07-28 revision use `server/discover` and need no session. Handshake-era clients stay common, so the store matters.

## What

- `symfony/mcp-bundle` 0.14.1 offers the session stores `file`, `memory`, `cache`, and `framework`. The `cache` store needs a PSR-16 service. Check the options of the installed bundle version with `bin/console config:dump-reference mcp` before the work starts.
- Use the `cache` store with a PostgreSQL-backed pool: Symfony Cache's DBAL adapter, wrapped as PSR-16 with `Symfony\Component\Cache\Psr16Cache`.
- Set a session lifetime, and prune expired entries with a scheduled command.
- Keep the `file` store as the default for a single container, selected by configuration.

## Acceptance criteria

1. A wire test sends `initialize` to one kernel and `tools/call` with the same `Mcp-Session-Id` to a second kernel. Both share the store, and the call succeeds.
2. An expired session gets the error that the MCP specification requires for an unknown session.
3. The prune command removes expired entries and keeps live ones.
4. The existing MCP wire tests pass with both stores.

## Dependencies

- M15 MCP server.
- Operations: more than one `php` container. Operations are outside these documents.
