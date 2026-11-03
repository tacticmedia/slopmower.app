# F107 Next-step line at the end of the conversation

Workflow, conventions, and status: [feature track](features.md). Requested by Jan on 2026-11-03: "I'm not opposed to this information, but it should not have this dominant location." He then agreed to the end of the conversation, above the reply box.

## Goal

The next-step line of the request page (F59) shows at the end of the conversation, after the last thread entry and above the reply box, as the merge box of a GitHub pull request. The top of the page holds the header, the action bar, and the tabs.

## Facts

Verified on 2026-11-03 on `main`:

- `RequestDetail.tsx` renders the line (`data-testid="next-step"`, `data-awaiting`) between the action bar and the closed-review banner, on both tabs. The text comes from `requestStage()` (`assets/requests/requestStage.ts`).
- The `Conversation` tab renders `ThreadBox` with `detail`. `ThreadBox` holds the comments and the reply box, so the line needs a place between them.
- The line repeats the status label, the action buttons, or the sidebar, except for two things: "Waiting for the agent to answer your reply." while the status is ready for review or won't do, and the highlight while it is the user's turn. An agent question already has the "Question" label in the thread.

## Decisions

- `ThreadBox` takes an optional `beforeReply` node and renders it after the comments, above the reply box. Without a reply box, for example in a closed review, it is the last part of the thread.
- The line keeps its text, its test ID, its `data-awaiting`, and its highlight. It gets the padding and the top border of the other thread sections.
- The Changes tab has no next-step line.

## Depends on

Nothing.

## In scope

- `assets/comments/ThreadBox.tsx` and its CSS module, `assets/routes/requests/RequestDetail.tsx` and its CSS module.
- `tests/ui/RequestDetailLayout.spec.ts`.
- `docs/user/requests.md`, the "Request detail" row of `docs/architecture.md`.

## Out of scope

- The texts of `requestStage()`.
- The thread on the code pages and the request list, which do not pass `beforeReply`.

## Acceptance tests

UI:

- The next-step line is below the last thread comment and above the reply box, and not between the action bar and the tabs.
- The line does not show on the Changes tab.
- The existing next-step test passes without a change to its texts.

## Verification

- `make front` and `make ui` are green.
- Mutation check: with the line back below the action bar, the new test fails.
