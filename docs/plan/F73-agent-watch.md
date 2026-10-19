# F73 Agent watch

Workflow, conventions, and status: [feature track](features.md). On 2026-10-15, Jan asked for a way to notify the agent when the user acts on a review, so that the agent responds without a prompt in the terminal, and asked whether MCP has a server push.

## Goal

In Claude Code, an agent that the user asks to keep watching a review wakes within 30 s of a user action on that review: a reply, a verify or reopen, a decision on a proposal, a new request, or a closed review. It spends no model turn while nothing happens.

## Facts

Verified on 2026-10-15 and 2026-10-20:

- **MCP push:**
  - Protocol 2025-11-25 has an optional GET SSE stream for server messages. mcp/sdk 0.8.1, the latest release, answers GET with 405 (`vendor/mcp/sdk/src/Server/Transport/StreamableHttpTransport.php:436-440`). A notification that is queued outside a request waits for the next POST of that session.
  - Protocol 2026-07-28 removes the GET stream. Change notifications go on the response stream of `subscriptions/listen`, which the client opens. The SDK implements it, but `config/packages/mcp.yaml` sets no `subscriptions.bus`, so a stream carries nothing, and each open stream holds a PHP worker for its lifetime.
  - The standard notifications are `resources/updated` (a URI only), `*/list_changed`, `progress`, and `message`. The spec does not require a client to give them to the model. Claude Code does not support resource subscriptions, refreshes only its tool list on `list_changed`, and shows progress in the UI.
  - The MCP Triggers and Events Working Group (chartered 2026-03-24) has no SEP past "ideating".
- **Claude Code channels** (`experimental['claude/channel']`, `notifications/claude/channel`, https://code.claude.com/docs/en/channels-reference):
  - The docs say a channel runs on the same machine and connects over stdio.
  - "A channel server that negotiates MCP protocol revision 2026-07-28 can't deliver channel messages" (https://code.claude.com/docs/en/mcp#push-messages-with-channels).
  - A research preview. A custom channel needs `--dangerously-load-development-channels` at each start, and Team and Enterprise organizations block channels until an owner enables them.
- **Monitor** (https://code.claude.com/docs/en/tools-reference#monitor-tool):
  - A Claude Code tool that runs a shell command. Each stdout line wakes the agent.
  - A watch lasts at most 30 minutes, and then the agent arms it again.
  - It is not available on Bedrock, Vertex, or Foundry, or with telemetry off.
- **V10** `GET /api/v1/reviews/{reviewId}/updates?cursor=`:
  - It accepts the bearer token through the `api_bearer` firewall.
  - In cursor mode it leaves out the caller's own events unless `includeOwn` is set, and it returns `cursor = S` also when it leaves events out (`docs/specs/rest-api-requests.md` section 4).
  - `UpdatesCursorTest` covers both.
  - A poll every 30 s uses 2 of the 300 calls per minute of `APP_RATE_LIMIT_API_TOKEN_PER_MINUTE`.

## Decisions

- **No MCP push.** Standard notifications never reach the model in Claude Code, and a channel from the HTTP server needs a GET stream that the SDK does not have. D21 stays true: the server pushes nothing to the agent.
- **Monitor watch (D81).** The skill gives a shell command for Monitor. It polls V10 every 30 s with the agent's cursor and prints one line per request with new events from others, one line per review event, `review closed`, or `watch stopped: HTTP <code>`. The agent then reads the details with `get_updates`, as before.
  - On 401, 403, 404, or 422, the command stops, because a retry cannot succeed. On another status or a network error, it waits and polls again.
  - A line names the request ID, `awaiting`, and the event types, so the agent knows where to look without a call.
- **Base URL:** the `slopmower` MCP server URL without `/_mcp`, from `.mcp.json` or `claude mcp get slopmower`. The server instructions stay agent-neutral.
- **Fallback:** without Monitor, `get_updates` after a pause, or `/loop` in Claude Code.

## Depends on

M15 (MCP server), M16 (V10), M23 (public skill), F65 (one skill text).

## In scope

- `docs/user/skill/SKILL.md` section 5, and its copy `.claude/skills/slopmower/SKILL.md`.
- `docs/user/connect-your-agent.md`.
- `docs/architecture.md` section 7.3 and D81.

## Out of scope

- A server change. V10 already serves the watch.
- Push through Mercure, a Claude Code channel bridge, and an agent run per user action: Candidates.
- Agents other than Claude Code. They poll as before.

## Acceptance tests

- **Functional** `SkillMatchesToolsTest`: the tool and error tables still match, and the project skill equals the public skill.
- **Functional** `RawSkillTest`: the served skill equals the file.
- **Unit** `SkillFrontmatterTest`: the body stays under 500 lines.
- No new automated test. `UpdatesCursorTest` covers the V10 behaviour that the command relies on. The command itself is a shell loop with a 30 s sleep against a running server, so the check below runs it by hand.

## Verification

On the dev stack, with the token of `APP_DEV_API_TOKEN` and a throwaway review:

1. Arm the documented command in a Monitor with the cursor of a `get_updates` call.
2. Reply as the agent. No line arrives.
3. Reply as the user in the UI. One line arrives within 30 s.
4. Close the review as the user. `review closed` arrives, and the watch ends.
5. Arm it with a wrong token. `watch stopped: HTTP 401` arrives, and the watch ends.
