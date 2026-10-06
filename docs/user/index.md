# Holistic Review

Holistic Review lets you review a full codebase at one commit, not only a diff. You read the code in a view that works like a GitHub pull request review. You leave comments on lines, line ranges, symbols, or files. Each comment is a change request.

Your coding agent, for example Claude Code, reads the change requests from Holistic Review. It asks questions in the request threads, makes the changes, and links its commits. You then verify or reopen each request.

## Pages

| Page | What it tells you |
|---|---|
| [Getting started](getting-started.md) | Sign in with GitHub, connect a repository, and start a review |
| [Run it locally](local-run.md) | Start Holistic Review with Docker on your computer and review repositories on your disk, without GitHub |
| [Connect your agent](connect-your-agent.md) | Create a token, configure the MCP server, install the skill, and give your agent a review |
| [Reviewing](reviewing.md) | Comment, go to declarations, work with the agent, verify, reopen, and close a review |

## How it works

1. You start a review of a repository. Holistic Review pins the review to one commit. The code in the review never changes.
2. You comment on the code. You can send each comment at once, or collect comments and submit them together.
3. Your agent finds the requests that wait for it. It answers, asks, or does the work. For each change it commits and links the commit to the request.
4. The agent marks a request "Ready for review". You look at the linked commits and verify or reopen the request.
5. When you are finished, you close the review.

You can have several open reviews of one repository at the same time, for example one per release. Each review has its own requests.
