# Slopmower REST API v1: projects and issues

This file continues [rest-api.md](rest-api.md). That file has the conventions, the [endpoint index](rest-api.md#2-endpoint-index), and the common errors, which this file does not repeat. Request bodies are defined in [inputs.schema.json](schemas/inputs.schema.json).

The endpoints are the issue tracker for the web UI and for agents without MCP (D120, D122). The issue endpoints call the same application services as the [issue tools](mcp.md#issue-tools) and return the same JSON. The model, the visibility, and the rules of the issue tools hold here too. P2 and P3 have no tool: only a signed-in user creates and changes a project.

## 1. Keys, actors, and rules

- **Keys.** A path names a project by its key and an issue by its key, not by a ULID. `{projectKey}` matches `ProjectKey` and `{issueKey}` matches `IssueKey` of [common.schema.json](schemas/common.schema.json). A key that does not match its pattern answers 404 `not_found`, as an unknown route does.
- **Check order.** A key that the caller cannot see answers 404 `not_found` before the 403 of the actor kind or the CSRF check and before the 400 or 422 of the body, as [README.md section 4](README.md#4-errors) orders.
- **Actors.** A session is the actor `user`, and a token is the actor `agent`. The approval rules of the [issue tools](mcp.md#issue-tools) bind an agent only. The content rules bind a user too: `problem`, a `plan` with text, `result`, and the `note` of `wont_do`. A user sets every status, also from `done` and `wont_do`, and changes every field in every status.
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

### P2 Create a project

- **Request:** `POST /api/v1/projects`
- **Authentication:** S
- **Body:** [`CreateProjectInput`](schemas/inputs.schema.json), `{key, name, approvalMode}`. Example: [fixtures/inputs.create-project.json](fixtures/inputs.create-project.json).
- **Success:** 201 [`Project`](schemas/project.schema.json) with `repositories` empty, and `Location: /api/v1/projects/{projectKey}`, the path of P3.
- **Errors:** 422 `invalid_input`:
  - on `key`, for a value that is no `ProjectKey`, and for a key that the tenant has already;
  - on `name`, for whitespace only or more than 255 characters;
  - on `approvalMode`, for a value outside `ApprovalMode`.

The key never changes, because the issue keys hold it. The server stores the name trimmed. The new project has no repository, so a token of one repository does not see it until P3 gives it that repository.

### P3 Change a project

- **Request:** `PATCH /api/v1/projects/{projectKey}`
- **Authentication:** S
- **Body:** [`UpdateProjectInput`](schemas/inputs.schema.json), `{name, approvalMode, repositoryIds}`. Absent or null keeps a value, and a body without a field changes nothing. Example: [fixtures/inputs.update-project.json](fixtures/inputs.update-project.json).
- **Success:** 200 [`Project`](schemas/project.schema.json).
- **Errors**, in this order:
  - 422 `invalid_input` on `name`, `approvalMode`, or `repositoryIds`, for a malformed value;
  - 404 `not_found`, for an ID of `repositoryIds` that the caller cannot see;
  - 422 `invalid_input` on `repositoryIds`, for a repository of another project. The message names the repository and that project.

`repositoryIds` is the whole list of the repositories of the project, at most 500 IDs: a repository that the list leaves out leaves the project, an empty list removes every repository, and an ID twice counts once. A refused call changes nothing. A token of one repository sees the project only while its repository belongs to it.

A new `approvalMode` holds for each later write. After a change to `user`, an issue in `in_progress` or `in_review` whose plan no user approved needs that approval ([I4](#i4-approve-the-plan)) before an agent writes its sections or sets `in_review`.

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
- **Success:** 200 [`IssueDetail`](schemas/issue.schema.json): the issue, its links, and its thread, each oldest first. A link shows the other issue. `allowedStatuses` holds the statuses that the rules allow the caller to set. Example: [fixtures/issue.detail.json](fixtures/issue.detail.json), with `history=true`.
- **Errors:** common errors only.

| Query | Type | Default | Notes |
|---|---|---|---|
| `history` | boolean | false | false: the current content. The thread has no replaced entry, and a `section_changed` entry has `body` null, because the issue has the text. true: every entry, and a `section_changed` entry has the text of its version in `body`. |

An entry is replaced when its `replacedBy` is not null, as the [issue tools](mcp.md#issue-tools) define. The MCP tool `get_issue` returns the same body.

### I3 Comment on an issue

- **Request:** `POST /api/v1/issues/{issueKey}/comments`
- **Authentication:** S, B
- **Body:** [`IssueCommentInput`](schemas/inputs.schema.json), `{body, label, replaces}`. `label` is a `CommentLabel` of [common.schema.json](schemas/common.schema.json), by default `comment`. `replaces` is the ID of a comment of this issue that no longer holds. Example: [fixtures/inputs.issue-comment.json](fixtures/inputs.issue-comment.json).
- **Success:** 201 [`Issue`](schemas/issue.schema.json), without its links and its thread. The comment is a `comment` entry of the thread with the label and with the caller as its actor. It is allowed in any status. With `replaces`, the named comment is replaced: it stays stored, and I2 without `history` and I5 leave it out.
- **Errors**, in this order:
  - 422 `invalid_input` on `body`, "Must contain a non-whitespace character.", on `label`, for a value outside `CommentLabel`, and on `replaces`, for a value that is no ULID;
  - 404 `not_found`, for a `replaces` that names no entry that the caller can see;
  - 422 `invalid_input` on `replaces`, for an entry that is no comment of this issue, for a comment that is replaced already, and for a comment of a user when the caller is a token.

A token call gives the same result as the MCP tool `comment_on_issue`.

### I4 Approve the plan

- **Request:** `POST /api/v1/issues/{issueKey}/plan-approval`
- **Authentication:** S
- **Body:** [`ApprovePlanInput`](schemas/inputs.schema.json), `{planRevision}`: `Issue.planRevision` of the plan that the user read. Example: [fixtures/inputs.approve-plan.json](fixtures/inputs.approve-plan.json).
- **Success:** 200 [`Issue`](schemas/issue.schema.json). The approval is a `plan_approved` entry with the user as its actor, `reviewer` and `reviewNote` null, and `planRevision`. It becomes `planApproval`, also after an approval of an agent. The entry of the earlier approval stays in the thread.
- **Errors:** 422 `invalid_input`:
  - on `plan`, when the issue has no plan;
  - on `planRevision`, when it is not the newest revision of the plan. The client reads the issue again and approves its `planRevision`.

The approval of a user is valid in every approval mode. An approval of an issue in `awaiting_user_input` sets the status `todo` and writes a `status_changed` entry, when the issue has a `problem`. Without a `problem` the status stays, because `todo` needs one. An issue in `done` or `wont_do` has no own error.

An agent does not call I4. Its approval is the MCP tool `approve_plan`, which records the review of another agent.

### I5 List the comments of a project

- **Request:** `GET /api/v1/projects/{projectKey}/comments`
- **Authentication:** S, B
- **Success:** 200 [`IssueCommentPage`](schemas/lists.schema.json): the `comment` entries of every issue of the project, each with the summary of its issue, its label, its actor, and its body. Entries of another kind and replaced comments are left out. Example: [fixtures/lists.issue-comment-page.json](fixtures/lists.issue-comment-page.json).
- **Errors:** 422 `invalid_input` for a cursor sent with another `label` than that of the page that returned it.

| Query | Type | Default | Notes |
|---|---|---|---|
| `label` | CommentLabel | all | One value. A comment stored without a label counts as `comment`. |
| `limit` | integer 1-200 | 50 | |
| `cursor` | Cursor | none | |

The list is sorted by the entry `id` descending, which is newest first. The MCP tool `list_comments` returns the same body.

### I6 Create an issue

- **Request:** `POST /api/v1/projects/{projectKey}/issues`
- **Authentication:** S, B
- **Body:** [`CreateIssueInput`](schemas/inputs.schema.json), `{title, problem, status}`. `status` is `draft`, `backlog`, or `todo`, by default `draft`. Example: [fixtures/inputs.create-issue.json](fixtures/inputs.create-issue.json).
- **Success:** 201 [`Issue`](schemas/issue.schema.json) with `Location: /api/v1/issues/{issueKey}`. The issue has the next number of the project. A `problem` writes a `section_changed` entry. The server stores the title trimmed.
- **Errors:** 422 `invalid_input`:
  - on `title`, for whitespace only, more than 200 characters, or a line break;
  - on `status`, for a status other than `draft`, `backlog`, and `todo`;
  - on `problem`, for `backlog` or `todo` without a problem.

A token call gives the same result as the MCP tool `create_issue`.

### I7 Change an issue

- **Request:** `PATCH /api/v1/issues/{issueKey}`
- **Authentication:** S, B
- **Body:** [`UpdateIssueInput`](schemas/inputs.schema.json), `{title, problem, plan, result, followUps}`. Absent or null keeps a field. A text has at least 1 character, so no section is cleared. Example: [fixtures/inputs.update-issue.json](fixtures/inputs.update-issue.json).
- **Success:** 200 [`Issue`](schemas/issue.schema.json). Each section with new text writes a `section_changed` entry with that text, which replaces the entry of the version before it, and a `plan` with new text clears `planApproval`. A value that equals the stored one changes nothing.
- **Errors:** 422 `invalid_input`:
  - for a body without a field;
  - on `title`, for whitespace only, more than 200 characters, or a line break;
  - on the field of a broken rule of the [issue tools](mcp.md#issue-tools), for example on `plan` for a plan of whitespace only in `in_progress`.

The call has no precondition: the last write wins. A token call gives the same result as the MCP tool `update_issue`.

### I8 Set the status

- **Request:** `POST /api/v1/issues/{issueKey}/status`
- **Authentication:** S, B
- **Body:** [`IssueStatusInput`](schemas/inputs.schema.json), `{status, note, reviewer}`. Example: [fixtures/inputs.issue-status.json](fixtures/inputs.issue-status.json).
- **Success:** 200 [`Issue`](schemas/issue.schema.json). The move writes a `status_changed` entry with `from`, `to`, `note`, and `reviewer`. Any status can follow any other status when the rules hold.
- **Errors:** 422 `invalid_input` on the field of a broken rule of the [issue tools](mcp.md#issue-tools): `problem`, `plan`, `result`, `note`, `reviewer`, `status`, or `issue`. The message says what is missing.

A user needs no approval of the plan and no reviewer. The status that the issue has answers 200 for a user and writes no entry, when the content rules hold: `wont_do` still needs its `note`. A token call gives the same result as the MCP tool `set_issue_status`.
