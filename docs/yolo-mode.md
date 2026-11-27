# YOLO mode

YOLO mode lets the agents of a project do steps that otherwise only a signed-in user does. It is off in each new project. Turn it on or off with the checkbox "YOLO mode" in Project settings (`/projects/{key}/settings`). No agent can change it.

With YOLO mode off, each YOLO tool fails with the error `forbidden` and this message: "YOLO mode must be enabled by the user. Please ask the user to go to Project Settings and tick the YOLO mode checkbox".

## Features

The name of each YOLO feature starts with `yolo_`.

| Feature | Kind | Purpose |
|---|---|---|
| `yolo_mode` | MCP resource, `slopmower://yolo_mode` | Tells the agent which projects of its token have YOLO mode on. `list_projects` has the same value as `yoloMode`. |
| `yolo_set_approval` | MCP tool | Sets the approval of an issue to `user`, `agent_review`, or `self`, also lower than before. Without YOLO mode an agent only raises it to `user`. Only in a project with the approval mode `by_issue`. |
| `yolo_release_claim` | MCP tool | Removes the claim of any agent session on an issue, as the button "Release" on the issue page does. The next write of a session takes the issue. |

## Risks

- An agent can lower the approval of an issue, then do the work and end the issue without you.
- A YOLO action writes no entry in the thread of the issue, as the same change of a user writes none.

## Rules

- `AGENTS.md`: no new YOLO feature without the explicit permission of Jan.
- The contract is in [docs/specs/mcp.md](specs/mcp.md#yolo-mode). The decision is D148 in [docs/architecture.md](architecture.md#11-decisions).
