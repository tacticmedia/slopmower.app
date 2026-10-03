# Holistic Review: requirements

Status: approved requirements for the proof of concept. The design that meets them is in [architecture.md](architecture.md). The contracts are in [specs/](specs/README.md). The implementation plan starts at [plan/README.md](plan/README.md).

## 1. Problem

Pull request review works only on a diff. The user wants to review the full codebase at a git ref, normally HEAD, not a change set.

When the user reads code, the user has many small remarks. The user selects a line, a line range, a function, method, or class, or a file, and writes a comment. Each comment is a small change request.

A coding agent, such as Claude Code, finds all open change requests without the user's help, does the work, and reports the result per request. The user then verifies or reopens each request.

## 2. Principles

These two principles control all decisions.

### Principle A: standard libraries for all AI-related parts

- Use the official, standard library for each AI-related part: the MCP server, the MCP transports, and the agent instruction files.
- For MCP, use the official SDK maintained under the `modelcontextprotocol` GitHub organization, and its official framework integration. Do not write JSON-RPC, protocol negotiation, or transport code. Do not use community MCP wrappers when an official SDK or an official framework integration exists.
- For agent instructions, use the open Agent Skills format, a `SKILL.md` file. Do not invent a custom format.
- If the official library does not support a necessary feature, tell the user. Do not write a workaround without the user's approval.

### Principle B: GitHub PR review experience, built from existing components

- The review experience is as near as possible to the GitHub pull request review UI: line-number gutter, "+" button on hover, click-and-drag or shift-click to select a line range, inline comment thread below the selected lines, reply box, "Resolve conversation" button, file tree, per-file "Viewed" checkbox, and a pending review that the user submits as one batch.
- Do not write an own code viewer, syntax highlighter, diff renderer, or comment-thread widget. Use existing, maintained open-source components. The selected component set is in [architecture.md](architecture.md), section 2.
- The front-end framework follows from the selected component.

## 3. Functional requirements

### FR1 Product and tenancy

- Holistic Review is a hosted, multi-tenant web application.
- A tenant is one developer account and all its data. A tenant never sees the data of another tenant.
- The proof of concept has one user per tenant. `User` and `Tenant` are separate, so that a tenant can get more users later.

### FR2 Sign-in

- A hosted run signs the user in with GitHub.
- A local development run can sign the user in without GitHub: `APP_LOCAL_SIGN_IN=<login>` signs the browser in as one local user and tenant. This works only when `APP_ENV=dev`. A local run needs no GitHub App.

### FR3 Repositories

- A repository comes from one of two sources:
  - the Holistic Review GitHub App, installed on repositories that the user chooses, with read-only access to contents;
  - a local directory under `APP_LOCAL_REPOSITORIES_DIR`, which Docker mounts read-only into the application.
- Local repositories are an instance feature. When `APP_LOCAL_REPOSITORIES_DIR` is set, every tenant of that instance may connect the repositories under it. The feature is meant for local and self-hosted runs. It is off when the variable is unset.
- The UI lists the git repositories found directly under the directory. The user connects one.
- The server keeps its own bare clone of each repository and fetches from the source. It never writes to the source.
- A local source has no webhooks. The server fetches on demand: at review start, at `link_commits`, at `mark_ready_for_review`, and when the user clicks "Refresh".

### FR4 Reviews

- A review is pinned to one commit SHA. The server resolves the ref, for example the default branch, to a SHA when the review starts.
- Reviews are revision-specific. Several open reviews of one repository can run at the same time and stay fully separate. Each request, draft, viewed-file mark, and update cursor belongs to exactly one review.
- The review header shows a copyable review ID and a ready prompt for the agent: "Work on Holistic Review <id>".
- The index of paths and symbols belongs to a commit of a repository, because it is code data. Reviews of the same commit share it.
- The server keeps the review commit and every linked commit in its clone, so that force-pushes and garbage collection cannot remove them.

### FR5 Closing a review

- The user closes a review with "Close review" in the review header, with an optional note.
- A closed review is read-only. Writes on it or on its requests fail with `review_closed`. Drafts cannot be submitted.
- Lists hide closed reviews unless the user asks for them.
- Reopening a closed review is outside the proof of concept: [review-reopen.md](backlog/review-reopen.md).

### FR6 Anchors

- Anchor types: line, line range, symbol, and file. A symbol is a class, interface, trait, enum, enum case, function, method, property, or constant.
- Symbols are extracted for PHP with `nikic/php-parser`. The design allows more languages later.
- Symbol selection feels like GitHub range selection. An action on a method selects the full method range, and the comment shows below the last line.
- Each anchor stores enough data to find its code again after changes: path, line range, symbol FQN where applicable, content snapshot, and the SHA.
- When re-anchoring fails, the request is flagged `orphaned`. A request is never dropped. The UI shows an outdated request as GitHub shows an outdated comment.

### FR7 Request lifecycle

- States:

  | State | Meaning |
  |---|---|
  | `open` | The user opened the request |
  | `in_progress` | An agent claimed it |
  | `ready_for_review` | The agent is finished |
  | `verified` | The user accepted the result |
  | `reopened` | The user rejected the result |
  | `wont_do` | The user or an agent declined the request, with a reason of 20 or more characters |

- Each state change is an event with actor, timestamp, and optional text. Replies are events too.
- The allowed transitions per actor are in [architecture.md](architecture.md), section 6. The states map to GitHub concepts where possible, for example `verified` is "Resolve conversation".
- An agent that marks a request `wont_do` gives evidence that the user can check. The user then verifies or reopens it.

### FR8 The agent conversation

- The agent discusses a request with the user in the request's thread, before or instead of doing the work.
- The agent can ask a question. The request then waits for the user and shows under "Waiting for you". The user's reply hands the turn back to the agent.
- The agent finds new work and new replies by polling. It keeps a cursor per review and asks for everything after it. The server never pushes to the agent.
- When several reviews of a repository are open and the user named none, the agent asks the user which one to work on.

### FR9 Commit links

- The agent links each commit to the requests that it covers. One commit can cover several requests of one review. The agent gives the full SHA.
- A commit link is separate from the status. A linked commit never changes the status. Only `mark_ready_for_review` says that the agent is finished.
- A link stays `pending` until the commit is in the server clone. For a GitHub repository the commit must be pushed. For a local repository a commit on a branch of the connected repository is enough.
- `mark_ready_for_review` requires every linked commit to be in the server clone after one fetch. Otherwise it fails with `commits_not_available`.
- The user sees the linked commits of each request and the diff of each commit.

### FR10 Human interface

- A web UI that follows Principle B.
- Screens: repositories from both sources, the file tree at the pinned SHA, the file view with inline threads, the list of requests with filters for status, "Waiting for you", path, and outdated, the request detail with the conversation and the linked commits, and settings.
- Actions: comment on a line, range, symbol, or file; reply; verify with "Resolve conversation"; reopen with a note; close as won't do; unassign a stale claim.
- A pending review: drafts that the user submits as one batch. The agent sees nothing before the submission.
- Per-file "Viewed" marks that stay for the whole review.
- Live updates: an agent reply shows in the open view without a reload.

### FR11 Keyboard access

- Every comment kind can be created with the keyboard only:
  - "Comment on lines" in the file header takes a start line and an end line, selects the range, and opens the draft box below the last line;
  - the "Symbols" menu selects the range of a symbol;
  - the "Comment on file" button opens a file comment;
  - on the last line of a selection, the code viewer's "+" receives focus with Tab and opens the draft box with Enter or Space.
- The draft box receives focus.
- A UI test proves a keyboard-only comment.

### FR12 Agent interface

- An MCP server over Streamable HTTP, built with the official PHP SDK `mcp/sdk` and the official Symfony integration `symfony/mcp-bundle`, as Principle A requires.
- A REST API with the same operations and the same JSON, for agents without MCP. There is no agent CLI.
- Both interfaces use one application service layer. No logic is duplicated.
- Operations: list repositories, list reviews, list requests with filters, get updates since a cursor, get one request with its current code context, claim, release, reply, link and unlink commits, mark ready for review, and mark won't do. The contracts are in [specs/](specs/README.md).
- An Agent Skill in `SKILL.md` teaches the agent the workflow: find the review, list what waits, claim, fix, test, commit, link, and mark ready.

### FR13 API tokens

- The user creates tokens in Settings. The token name is the agent name on every event made with the token.
- Scope: all repositories of the tenant, or one repository. A scoped token answers `not_found` for other repositories.
- Optional expiry date.
- The secret is `hr_` followed by 32 random bytes in base64url. It is shown once. The server stores only its SHA-256 hash.
- The user can revoke a token. The last use is shown.

### FR14 Public documentation

- The application renders the user documentation from `docs/user/*.md` at `/docs`, without sign-in.
- It serves the Agent Skill raw at `/docs/skill/SKILL.md`, so that one `curl` command installs it into `.claude/skills/holistic-review/SKILL.md` of the user's repository.
- Settings links to the documentation and to the skill.

### FR15 Storage and safety

- The review data is safe when the web UI and the agent write at the same time.
- Every review and request state change and every comment is an event in an append-only log with an actor. Drafts, viewed-file marks, repositories, and API tokens are plain rows.
- The user can delete a review, the data of one repository, or the whole account. When GitHub access to a repository ends, the server deletes its clone and index at once and keeps the reviews until the user deletes them.

## 4. Non-goals of the proof of concept

| Non-goal | Where it is kept |
|---|---|
| Teams and several users per tenant | [multiple-users-per-tenant.md](backlog/multiple-users-per-tenant.md) |
| Billing | Not planned |
| Operations: production hosting, TLS, backups, monitoring, and CI | The operator's work. The documentation covers only the local development environment. |
| Usage limits and rate limiting | [usage-limits.md](backlog/usage-limits.md) |
| Database hardening: row-level security and separate database roles | [database-hardening.md](backlog/database-hardening.md) |
| Symbol anchors for languages other than PHP | [more-languages.md](backlog/more-languages.md) |
| Sync to GitHub or GitLab | Kept possible as a later export |

The full list of deferred work is in the [backlog](backlog/README.md).

## 5. Standing rules

These rules come from the user's brief. They apply to every session, in every milestone.

1. Write documentation, comments, and messages in ASD-STE100 Simplified Technical English.
2. Do not guess. Verify each fact. If you cannot verify a fact, say so.
3. Use context7 to learn how a library works.
4. When you review files, read them completely.
5. Keep code comments to the minimum. First try to make the code self-explanatory (for example `$fileSizeBytes`, not `$fileSize // bytes`).
6. Use the newest language features that the installed PHP version supports.
7. Search online for the current latest version of each library before you use it.
8. All PHP code must pass PHPStan level 8.
9. Test all code: unit tests for simple concepts, functional tests for code that needs the database or the service container, Vitest tests for pure TypeScript, Playwright UI tests for front-end behaviour that uses JavaScript, and full-stack E2E tests for flows across the browser, PHP, and the database.
10. Never mock the dependency injection container.
11. Base test cases on `Symfony\Bundle\FrameworkBundle\Test\KernelTestCase` and its subclasses.
12. Add a TestDox description to each test case. Make it as short as possible without loss of information.
13. Do not use `-v` or `--verbose` with PHPUnit. Use `--debug`.
14. Do not commit. I review and commit the code.
