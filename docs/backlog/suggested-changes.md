# Suggested changes

Status: backlog. The proof of concept has no suggested changes.

## Why

- On GitHub a reviewer can propose the exact replacement lines in a comment. For small fixes this removes a round trip with the agent.
- The agent gets an unambiguous instruction for the anchored lines.

## What

- A request body or a reply can contain a fenced block with the info string `suggestion`, as on GitHub. The block holds the proposed lines for the anchored range.
- Only `line`, `range`, and `symbol` anchors take suggestions.
- The comment box gets an **Insert suggestion** button that inserts the block, prefilled with the anchored lines at the review commit.
- The UI renders a suggestion as a diff of the anchored lines against the proposed lines, with `@pierre/diffs`.
- `get_request` adds a property `suggestions: [{threadEntrySeq, range, lines}]`, parsed on the server. This is an additive change within v1.
- The agent applies the suggestion in its working tree, commits, and links the commit as usual. There is no "Commit suggestion" button, because the server never writes to repositories.
- The skill tells the agent to apply a suggestion as written, or to explain in the thread why it cannot.

## Acceptance criteria

1. A body with a `suggestion` block renders as a diff in the thread. Other fenced blocks render as code.
2. `get_request` returns the parsed suggestions, validated against the updated schema.
3. A suggestion on a `file` anchor is refused with `invalid_input`.
4. A Panther test inserts a suggestion with the button and sees the diff after submit.

## Dependencies

- M21 commenting UI, M22 verify UI, and M30 verify view diffs.
