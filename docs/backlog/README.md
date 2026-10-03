# Backlog

Work outside the proof of concept. Each item has the sections Why, What, Acceptance criteria, and Dependencies. Milestones never create backlog files. They can add entries to an existing item or to this list.

| Item | Summary |
|---|---|
| [Database hardening](database-hardening.md) | Row-level security on every tenant table, separate owner, runtime, and maintenance roles, and an append-only event log in the database |
| [Usage limits and rate limiting](usage-limits.md) | Per-tenant limits for repositories, open reviews, requests, and clone size, and API and MCP rate limits with Symfony RateLimiter |
| [MCP OAuth](mcp-oauth.md) | OAuth for the MCP server, so that agents connect without a copied token |
| [Reopen a closed review](review-reopen.md) | Reopen a closed review and make it writable again |
| [Carry over open requests](request-carry-over.md) | Move open requests of an older review to a new review, re-anchored at the new commit |
| [More languages](more-languages.md) | Symbol anchors for languages other than PHP, with tree-sitter |
| [Suggested changes](suggested-changes.md) | Proposed replacement lines in a comment, as on GitHub |
| [Several users per tenant](multiple-users-per-tenant.md) | Invite members into a tenant and share its reviews |
| [Skill plugin](skill-plugin.md) | Distribute the skill and the MCP configuration as a Claude Code plugin |
| [Shared MCP session store](mcp-session-store.md) | MCP sessions in PostgreSQL, for more than one `php` container |
| [Quality-assurance pipeline](qa-pipeline.md) | GitHub Actions workflow that runs every test suite, linter, static analyzer, and audit on each push and pull request |
