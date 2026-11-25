# Slopmower REST API v1: projects and issues

This file continues [rest-api.md](rest-api.md). That file has the conventions, the [endpoint index](rest-api.md#2-endpoint-index), and the common errors, which this file does not repeat. Request bodies are defined in [inputs.schema.json](schemas/inputs.schema.json).

The endpoints are the issue tracker for the web UI and for agents without MCP (D120, D122). The issue endpoints call the same application services as the [issue tools](mcp.md#issue-tools) and return the same JSON. The model, the visibility, and the rules of the issue tools hold here too. P2 and P3 have no tool: only a signed-in user creates and changes a project. I9 has no tool: the graph is for the web UI. I10 is the twin of the tool `get_issue_updates`, for the watch loop of the `slopmower-issues` skill (D128).

## 1. Keys, actors, and rules

- **Keys.** A path names a project by its key and an issue by its key, not by a ULID. `{projectKey}` matches `ProjectKey` and `{issueKey}` matches `IssueKey` of [common.schema.json](schemas/common.schema.json). A key that does not match its pattern answers 404 `not_found`, as an unknown route does.
- **Check order.** A key that the caller cannot see answers 404 `not_found` before the 403 of the actor kind or the CSRF check and before the 400 or 422 of the body, as [README.md section 4](README.md#4-errors) orders.
- **Actors.** A session is the actor `user`, and a token is the actor `agent`. The approval rules of the [issue tools](mcp.md#issue-tools) bind an agent only. The content rules bind a user too: `problem`, a `plan` with text, `result`, and the `note` of `wont_do`. A user sets every status, also from `done` and `wont_do`, and changes every field in every status.
- **Refusals.** A refusal of a rule is 422 `invalid_input`, and `details.violations[0]` has the field and the reason. The endpoints never answer `invalid_transition` or `review_closed`.

## 2. Endpoints

### P1 List projects

- **Request:** `GET /api/v1/projects`
- **Authentication:** S, B
- **Success:** 200 [`ProjectPage`](schemas/lists.schema.json): the projects that the caller sees, each with its `approvalGuidelines` and the names of its repositories that the caller sees, in name order. Example: [fixtures/lists.project-page.json](fixtures/lists.project-page.json).
- **Errors:** common errors only.

| Query | Type | Default | Notes |
|---|---|---|---|
| `limit` | integer 1-500 | 100 | |
| `cursor` | Cursor | none | |

The list is sorted by `key`. The MCP tool `list_projects` returns the same body.

### P2 Create a project

- **Request:** `POST /api/v1/projects`
- **Authentication:** S
- **Body:** [`CreateProjectInput`](schemas/inputs.schema.json), `{key, name, approvalMode, approvalGuidelines}`. `approvalGuidelines` is optional. Example: [fixtures/inputs.create-project.json](fixtures/inputs.create-project.json).
- **Success:** 201 [`Project`](schemas/project.schema.json) with `repositories` empty, and `Location: /api/v1/projects/{projectKey}`, the path of P3.
- **Errors:** 422 `invalid_input`:
  - on `key`, for a value that is no `ProjectKey`, and for a key that the tenant has already;
  - on `name`, for whitespace only or more than 255 characters;
  - on `approvalMode`, for a value outside `ApprovalMode`;
  - on `approvalGuidelines`, for more than 10,000 characters.

The key never changes, because the issue keys hold it. The server stores the name trimmed. It stores `approvalGuidelines` as sent, and whitespace only as null. The new project has no repository, so a token of one repository does not see it until P3 gives it that repository.

### P3 Change a project

- **Request:** `PATCH /api/v1/projects/{projectKey}`
- **Authentication:** S
- **Body:** [`UpdateProjectInput`](schemas/inputs.schema.json), `{name, approvalMode, approvalGuidelines, repositoryIds}`. Absent or null keeps a value, and a body without a field changes nothing. Example: [fixtures/inputs.update-project.json](fixtures/inputs.update-project.json).
- **Success:** 200 [`Project`](schemas/project.schema.json).
- **Errors**, in this order:
  - 422 `invalid_input` on `name`, `approvalMode`, `approvalGuidelines`, or `repositoryIds`, for a malformed value;
  - 404 `not_found`, for an ID of `repositoryIds` that the caller cannot see;
  - 422 `invalid_input` on `repositoryIds`, for a repository of another project. The message names the repository and that project.

`repositoryIds` is the whole list of the repositories of the project, at most 500 IDs: a repository that the list leaves out leaves the project, an empty list removes every repository, and an ID twice counts once. A refused call changes nothing. A token of one repository sees the project only while its repository belongs to it.

A new `approvalMode` holds for each later write. After a change to `user`, an issue in `in_progress` or `in_review` whose plan no user approved needs that approval ([I4](#i4-approve-the-plan)) before an agent writes its sections or sets `in_review`. In `by_issue`, the `approval` of each issue binds it, as "Approval mode" of the [issue tools](mcp.md#issue-tools) says (D129).

Whitespace only in `approvalGuidelines` removes the guidelines. They stay when the mode changes.

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
- **Success:** 200 [`IssueDetail`](schemas/issue.schema.json): the issue, its links, and its thread, each oldest first. A link shows the other issue and its `kind` for the issue that is read: `relates`, `blocked_by` when this issue waits for the other issue, or `blocks` when the other issue waits for this issue (D126). `allowedStatuses` holds the statuses that the rules allow the caller to set. Example: [fixtures/issue.detail.json](fixtures/issue.detail.json), with `history=true`.
- **Errors:** common errors only.

| Query | Type | Default | Notes |
|---|---|---|---|
| `history` | boolean | false | false: the current content. The thread has no replaced entry, and a `section_changed` entry has `body` null, because the issue has the text. true: every entry, and a `section_changed` entry has the text of its version in `body`. |

An entry is replaced when its `replacedBy` is not null, as the [issue tools](mcp.md#issue-tools) define. The MCP tool `get_issue` returns the same body.

### I3 Comment on an issue

- **Request:** `POST /api/v1/issues/{issueKey}/comments`
- **Authentication:** S, B
- **Body:** [`IssueCommentInput`](schemas/inputs.schema.json), `{body, label, replaces, questions}`. `label` is a `CommentLabel` of [common.schema.json](schemas/common.schema.json), by default `comment`. `replaces` is the ID of a comment of this issue that no longer holds. `questions` is 0 to 10 items of `{question, detail, answer}`, and `body` can be empty with them. Example: [fixtures/inputs.issue-comment.json](fixtures/inputs.issue-comment.json).
- **Success:** 201 [`Issue`](schemas/issue.schema.json), without its links and its thread. The comment is a `comment` entry of the thread with the label and with the caller as its actor. It is allowed in any status. With `replaces`, the named comment is replaced: it stays stored, and I2 without `history` and I5 leave it out.
- **Questions:** "Questions" of the [issue tools](mcp.md#issue-tools) has the rules (D124). A token asks: no item has an `answer`, and the comment gets the label `question`. A session answers: `replaces` names an open question comment, `questions` repeats its questions in order, each with an `answer`, and the comment gets the label `answer` and the `detail` of each question. The server sets both labels, whatever `label` says, and changes no status. Example of an answer: `{"body":"","replaces":"01K7F3G0H1J2K3M4N5P6Q7R8S9","questions":[{"question":"Remove `InvoiceTotals::round()` in 3.0?","answer":"Yes"}]}`.
- **Errors**, in this order:
  - 422 `invalid_input`:
    - on `body`, "Must contain a non-whitespace character.", for a comment without questions;
    - on `label`, for a value outside `CommentLabel`, and for `question` or `answer` on a comment without questions;
    - on `replaces`, for a value that is no ULID, and for answers without it;
    - on `questions`, for more than 10 items, and on `questions.<index>.question`, `.detail`, or `.answer`, for an item that breaks a rule of D107: a question that is not one line of 1 to 200 characters, a `detail` of more than 4,000 characters, an `answer` from a token, and an item without an `answer` from a session;
  - 404 `not_found`, for a `replaces` that names no entry that the caller can see;
  - 422 `invalid_input` on `replaces`, for an entry that is no comment of this issue, for a comment that is replaced already, for a comment of a user when the caller is a token, and, with answers, for a comment that is no open question comment;
  - 422 `invalid_input` on `questions`, for answers that do not repeat the questions of that comment in order.

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
- **Success:** 200 [`IssueCommentPage`](schemas/lists.schema.json): the `comment` entries of every issue of the project, each with the summary of its issue, its label, its actor, its body, and its questions. Entries of another kind and replaced comments are left out. With the label `question`, the list has the question comments that wait for an answer, because the answers replace their question comment. Example: [fixtures/lists.issue-comment-page.json](fixtures/lists.issue-comment-page.json).
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
- **Body:** [`CreateIssueInput`](schemas/inputs.schema.json), `{title, problem, status, approval}`. `status` is `draft`, `backlog`, or `todo`, by default `draft`. `approval` is `user`, `agent_review`, or `self`, only in a project with the approval mode `by_issue`. Without it the issue reads as `user`. Example: [fixtures/inputs.create-issue.json](fixtures/inputs.create-issue.json).
- **Success:** 201 [`Issue`](schemas/issue.schema.json) with `Location: /api/v1/issues/{issueKey}`. The issue has the next number of the project. A `problem` writes a `section_changed` entry. The server stores the title trimmed.
- **Errors:** 422 `invalid_input`:
  - on `title`, for whitespace only, more than 200 characters, or a line break;
  - on `status`, for a status other than `draft`, `backlog`, and `todo`;
  - on `approval`, for `by_issue`, and for a project of another approval mode;
  - on `problem`, for `backlog` or `todo` without a problem.

A token call gives the same result as the MCP tool `create_issue`.

### I7 Change an issue

- **Request:** `PATCH /api/v1/issues/{issueKey}`
- **Authentication:** S, B
- **Body:** [`UpdateIssueInput`](schemas/inputs.schema.json), `{title, problem, plan, result, followUps, approval}`. Absent or null keeps a field. A text has at least 1 character, so no section is cleared. A session sets any `approval`, and a token changes it only to `user`. Example: [fixtures/inputs.update-issue.json](fixtures/inputs.update-issue.json).
- **Success:** 200 [`Issue`](schemas/issue.schema.json). Each section with new text writes a `section_changed` entry with that text, which replaces the entry of the version before it, and a `plan` with new text clears `planApproval`. A change of `approval` writes no entry. A value that equals the stored one changes nothing.
- **Errors:** 422 `invalid_input`:
  - for a body without a field;
  - on `title`, for whitespace only, more than 200 characters, or a line break;
  - on `approval`, for `by_issue`, for a project of another approval mode, and for a change of a token to another value than `user`;
  - on the field of a broken rule of the [issue tools](mcp.md#issue-tools), for example on `plan` for a plan of whitespace only in `in_progress`.

The call has no precondition: the last write wins. A token call gives the same result as the MCP tool `update_issue`.

### I8 Set the status

- **Request:** `POST /api/v1/issues/{issueKey}/status`
- **Authentication:** S, B
- **Body:** [`IssueStatusInput`](schemas/inputs.schema.json), `{status, note, reviewer}`. Example: [fixtures/inputs.issue-status.json](fixtures/inputs.issue-status.json).
- **Success:** 200 [`Issue`](schemas/issue.schema.json). The move writes a `status_changed` entry with `from`, `to`, `note`, and `reviewer`. Any status can follow any other status when the rules hold.
- **Errors:** 422 `invalid_input` on the field of a broken rule of the [issue tools](mcp.md#issue-tools): `problem`, `plan`, `result`, `note`, `reviewer`, `status`, or `issue`. The message says what is missing.

A user needs no approval of the plan and no reviewer. The status that the issue has answers 200 for a user and writes no entry, when the content rules hold: `wont_do` still needs its `note`. A token call gives the same result as the MCP tool `set_issue_status`.

### I9 Get the dependency graph of a project

- **Request:** `GET /api/v1/projects/{projectKey}/graph`
- **Authentication:** S, B
- **Success:** 200 [`IssueGraph`](schemas/issue.schema.json), `{issues, links, otherOpenIssues}` (D126). Example: [fixtures/issue.graph.json](fixtures/issue.graph.json).
- **Errors:** common errors only.

An issue is open while its status is not `done` and not `wont_do`. A dependency is a `blocked_by` link of two open issues: the tool `link_issues` with `kind: blocked_by` sets it.

- `issues` has the summaries of:
  - each open issue of the project that waits for an open issue, or that an open issue waits for;
  - each open issue of the project with the status `awaiting_user_input` or `in_review`, also without a link;
  - each open issue of another project at the other end of a dependency of an issue of the project.
- `links` has each dependency with at least one issue of the project as `{issue, blockedBy}`, two issue keys: `issue` waits for `blockedBy`. Both keys are in `issues`.
- `otherOpenIssues` is the count of the open issues of the project that are not in `issues`.

`issues` is sorted by project key, then by number. `links` is sorted in that order by `issue`, then by `blockedBy`. The body has no page: it holds every such issue. A link with an issue that the caller cannot see is left out, so a token of one repository gets no issue of another project. A cycle is no error, and each of its links is in `links`. No MCP tool returns the graph: an agent reads `kind` of the links of `get_issue`.

### I10 Get the updates of a project

- **Request:** `GET /api/v1/projects/{projectKey}/updates`
- **Authentication:** S, B
- **Success:** 200 [`IssueUpdatesPage`](schemas/issue.schema.json), `{cursor, hasMore, issues, entries}` (D128). Examples: [fixtures/issue.updates-first-call.json](fixtures/issue.updates-first-call.json) without a cursor, [fixtures/issue.updates-cursor.json](fixtures/issue.updates-cursor.json) with one.
- **Errors:** 422 `invalid_input` on `cursor` for a cursor ahead of the server, and on `cursor` or `limit` for a value outside its range.

| Query | Type | Default | Notes |
|---|---|---|---|
| `cursor` | integer, at least 0 | none | `cursor` of the previous answer |
| `limit` | integer 1-200 | 50 | Issues without a cursor, entries with one |

Each write that appends an entry to the thread of an issue gives the entry the next number of a sequence of its project, under the row lock of the project. `cursor` is such a number.

- **Without a cursor:** `issues` holds the summaries of the issues of the project in `todo`, `in_progress`, `awaiting_user_input`, and `in_review`, newest `updatedAt` first, then by ID, at most `limit`. `entries` is empty, `hasMore` is false, and `cursor` is the newest number of the project, 0 for a project without a numbered entry.
- **With a cursor:** `entries` holds the entries after `cursor`, oldest first, each as `{issue, entry}`: the summary of its issue and the entry. `issues` is empty. The read takes the newest number of the project first, then the entries up to it, so it misses no entry that commits later. It reads at most `limit` entries. With more, `hasMore` is true and `cursor` is the number of the last entry that it read; else `cursor` is the newest number. A page can hold fewer than `limit` entries, also none, while `hasMore` is true, because it leaves out entries.
- **Left out:** the entries of the caller, and each entry that is replaced at the time of the read. An entry reads as in I2 without `history`: `replacedBy` is null, and a `section_changed` entry has `body` null, because the issue has the text. An entry from before the sequence has no number and comes before every cursor.
- **The caller** is the actor of the call: a session is its user, and a token is the agent of its name and its user. Two tokens of one user with the same name are one caller, so an agent does not see the entries of another session with its token name, for example the review comment that it recorded.
- **No entry:** a title change, a change of `approval`, a link, and a new issue without a `problem` write no entry, so no update tells of them.

The answers of a user are one `comment` entry with the label `answer`, each question with its `answer`, which replaces the question comment (D124). A plan approval of a user is a `plan_approved` entry, followed by a `status_changed` entry to `todo` when the issue was in `awaiting_user_input`. The MCP tool `get_issue_updates` returns the same body.
