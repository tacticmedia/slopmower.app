# F108 Structured agent questions

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "I want the agent to be able to attach questions to the comment it's sending to slopmower, and I want slopmower UI to show these questions under the comment body, so that when the user records the answers, both questions and answers become simply another comment block in the UI, and a structured part of the MCP payload where the agent can make the most of it." The layout he asked for, above the comment box:

```
[ question ]
[ longer question text, giving more context to the question ]
[Button Yes] [Button No] [Input text for custom response]
---
[ another question ] ...
```

## Goal

An agent attaches up to 10 questions to a comment. The web UI shows them above the reply box, each with "Yes", "No", and a text field. "Send answers" records one user comment that holds every question with its answer. The agent reads the questions and the answers as structured data in `get_request` and `get_updates`.

## Facts

Verified on 2026-11-03 on `main` at `f4a1726f`:

- An agent asks with `reply_to_request` or Q4 and `question: true`, and the question is the Markdown body. `RequestCommentedEvent` holds `commentId`, `body`, `question`, `batchId`. `AwaitingPolicy` gives the turn to the user after an agent question.
- `EventNormalizer` writes the same fields for every event type, null or false where they do not apply. `event.schema.json` and `thread-entry.schema.json` require every field.
- The MCP SDK validates tool arguments against the `inputSchema` of `docs/specs/mcp-tools.json` (F85, error -32602). The decider validates the REST and MCP commands the same way.
- `ThreadBox` renders the comments, then `beforeReply`, then the reply box. The request detail and the code view both render it.

## Decisions

D107 in [architecture section 11](../architecture.md#11-decisions):

- `request.commented` gets `questions: list<Question>`, read as `[]` when missing. `Question` is `{question, detail, answer}`: `question` is one line of 1 to 200 characters after trimming, stored trimmed; `detail` is null or Markdown of at most 4,000 characters; `answer` is null or text of 1 to 4,000 characters after trimming.
- Q4 `CommentInput` and `reply_to_request` take `questions`, 0 to 10 items. MCP `questions` items take `question` and `detail` only.
- An agent's question has `answer` null, and a comment with questions from an agent is a question: the event stores `question: true`. A user's comment with questions answers them: every item has an answer. The decider refuses an answer from an agent ("Only a user answers a question.") and a missing answer from a user ("Is required.").
- The body may be empty when `questions` is not empty. Otherwise the existing rule stays: it must contain a non-whitespace character.
- The server does not link an answer comment to the question comment. The answer comment repeats each question, so it reads on its own.
- `event.schema.json` and `thread-entry.schema.json` require `questions`, `[]` for every other entry.
- The web UI takes the questions of the latest agent comment with questions as open while no later user comment has questions. Open questions show above the reply box, after the next-step line, and not under their comment. "Yes" and "No" set the answer to `Yes` or `No`; text in the field replaces it. "Send answers" is enabled when every question has an answer, and sends Q4 with an empty body and `questions` with `question` and `answer`. The UI renders other questions under the body of their comment: an agent's as the question and its detail, a user's as the question and its answer.
- Selected but unsent answers count as an unsaved comment (F93).

## Depends on

F109, for the order of the reply area.

## In scope

- Specs: `docs/specs/schemas/common.schema.json` (`Question`), `inputs.schema.json` (`CommentInput.questions`, `QuestionInput`), `event.schema.json`, `thread-entry.schema.json`, every fixture with a thread entry or an event, `docs/specs/mcp-tools.json`, `mcp.md` (server instructions step 5, tool 4.8), `rest-api-requests.md` (Q4), `docs/specs/README.md` when a fixture is added; `docs/architecture.md` (event table, D107).
- Back end: `RequestCommentedEvent`, a `CommentQuestion` value object, `Reply`, `RequestDecider`, `CommentInput`, `RequestCommandController`, `McpTools::replyToRequest()`, `EventNormalizer`, `BundledSchemas` through `app:schema:bundle`.
- Front end: `assets/api/types/` (thread entry, event, inputs), `threadView.ts`, `ThreadBox.tsx`, a question form component, the UI fake and builders.
- The skill (`docs/user/skill/SKILL.md` and its copy `.claude/skills/slopmower/SKILL.md`), `docs/user/requests.md`.

## Out of scope

- Answer options other than Yes, No, and text.
- Answers to some of the questions only.
- Questions on a draft, a suggestion, a proposal, or a new request.
- A link from the answer comment to the question comment.

## Acceptance tests

Functional (PHPUnit):

- An agent's Q4 with two questions answers 201; the event has `question: true` and both questions with `answer` null; the request waits for the user.
- A user's Q4 with an empty body and answered questions answers 201, and the request waits for the agent.
- 422 for: an agent's answer, a user's missing answer, 11 questions, a question with a line break, an empty body without questions.
- `reply_to_request` with `questions` stores them; `get_request` shows them on the thread entry.
- Event round trip with questions, and a stored payload without `questions` reads as `[]`.

UI (Playwright on the mocked API):

- An agent comment with two questions shows them above the reply box, and not under the comment. "Send answers" stays disabled until both have an answer. "Yes" on one and text on the other send Q4 with `[{question, answer: "Yes"}, {question, answer: <text>}]` and an empty body.
- After the answer, the form is gone, and the answer comment shows each question with its answer.

## Verification

- `make check` is green.
- Mutation check: with the "every question has an answer" rule of the decider removed, the 422 test fails.
