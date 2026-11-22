# Slopmower REST API v1: projects and issues

This file continues [rest-api.md](rest-api.md). That file has the conventions, the [endpoint index](rest-api.md#2-endpoint-index), and the common errors, which this file does not repeat. Request bodies are defined in [inputs.schema.json](schemas/inputs.schema.json).

The endpoints are the issue tracker for the web UI and for agents without MCP (D120). They call the same application services as the [issue tools](mcp.md#issue-tools) and return the same JSON. The model, the visibility, and the rules of the issue tools hold here too.

## 1. Keys, actors, and rules

- **Keys.** A path names a project by its key and an issue by its key, not by a ULID. `{projectKey}` matches `ProjectKey` and `{issueKey}` matches `IssueKey` of [common.schema.json](schemas/common.schema.json). A key that does not match its pattern answers 404 `not_found`, as an unknown route does.
- **Check order.** A key that the caller cannot see answers 404 `not_found` before the 403 of the actor kind or the CSRF check and before the 400 or 422 of the body, as [README.md section 4](README.md#4-errors) orders.
- **Actors.** A session is the actor `user`, and a token is the actor `agent`. The approval rules of the [issue tools](mcp.md#issue-tools) bind an agent only.
- **Refusals.** A refusal of a rule is 422 `invalid_input`, and `details.violations[0]` has the field and the reason. The endpoints never answer `invalid_transition` or `review_closed`.

## 2. Endpoints

### P1 List projects

- **Request:** `GET /api/v1/projects`
- **Authentication:** S, B
- **Success:** 200 [`ProjectPage`](schemas/lists.schema.json): the projects that the caller sees, each with the names of its repositories that the caller sees, in name order. Example: [fixtures/lists.project-page.json](fixtures/lists.project-page.json).
- **Errors:** common errors only.

| Query | Type | Default | Notes |
|---|---|---|---|
| `limit` | integer 1-500 | 100 | |
| `cursor` | Cursor | none | |

The list is sorted by `key`. The MCP tool `list_projects` returns the same body.

### I1 List issues

- **Request:** `GET /api/v1/projects/{projectKey}/issues`
- **Authentication:** S, B
- **Success:** 200 [`IssuePage`](schemas/lists.schema.json): the summaries of the issues of the project. Example: [fixtures/lists.issue-page.json](fixtures/lists.issue-page.json).
- **Errors:** 422 `invalid_input` for a cursor sent with another `status` than that of the page that returned it.

| Query | Type | Default | Notes |
|---|---|---|---|
| `status` | IssueStatus | all | One value |
| `limit` | integer 1-200 | 50 | |
| `cursor` | Cursor | none | |

The list is sorted by `updatedAt` descending, then by `id` descending. The MCP tool `list_issues` returns the same body.

### I2 Get an issue

- **Request:** `GET /api/v1/issues/{issueKey}`
- **Authentication:** S, B
- **Success:** 200 [`IssueDetail`](schemas/issue.schema.json): the issue, its links, and its thread, each oldest first. A link shows the other issue. `allowedStatuses` holds the statuses that the rules allow the caller to set. Example: [fixtures/issue.detail.json](fixtures/issue.detail.json).
- **Errors:** common errors only.

The MCP tool `get_issue` returns the same body.

### I3 Comment on an issue

- **Request:** `POST /api/v1/issues/{issueKey}/comments`
- **Authentication:** S, B
- **Body:** [`IssueCommentInput`](schemas/inputs.schema.json), `{body}`. Example: [fixtures/inputs.issue-comment.json](fixtures/inputs.issue-comment.json).
- **Success:** 201 [`Issue`](schemas/issue.schema.json), without its links and its thread. The comment is a `comment` entry of the thread with the caller as its actor. It is allowed in any status.
- **Errors:** 422 `invalid_input` on `body`, "Must contain a non-whitespace character.".

A token call gives the same result as the MCP tool `comment_on_issue`.

### I4 Approve the plan

- **Request:** `POST /api/v1/issues/{issueKey}/plan-approval`
- **Authentication:** S
- **Body:** [`ApprovePlanInput`](schemas/inputs.schema.json), `{planRevision}`: `Issue.planRevision` of the plan that the user read. Example: [fixtures/inputs.approve-plan.json](fixtures/inputs.approve-plan.json).
- **Success:** 200 [`Issue`](schemas/issue.schema.json). The approval is a `plan_approved` entry with the user as its actor, `reviewer` and `reviewNote` null, and `planRevision`. It becomes `planApproval` and replaces an earlier approval, also one of an agent.
- **Errors:** 422 `invalid_input`:
  - on `plan`, when the issue has no plan;
  - on `planRevision`, when it is not the newest revision of the plan. The client reads the issue again and approves its `planRevision`.

The approval of a user is valid in every approval mode. An approval of an issue in `awaiting_user_input` sets the status `todo` and writes a `status_changed` entry, when the issue has a `problem`. Without a `problem` the status stays, because `todo` needs one. An issue in `done` or `wont_do` has no own error.

An agent does not call I4. Its approval is the MCP tool `approve_plan`, which records the review of another agent.
