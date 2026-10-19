# Help center

Slopmower lets you review a full codebase at one commit, not only a diff. You read the code in a view that works like a GitHub pull request review. You leave comments on lines, line ranges, symbols, or files. Each comment is a change request.

Your coding agent, for example Claude Code, reads the change requests from Slopmower. It asks questions in the request threads, makes the changes, and links its commits. You then verify or reopen each request.

## How it works

1. You start a review of a repository. Slopmower pins the review to one commit. The code in the review never changes.
2. You comment on the code. You can send each comment at once, or collect comments and submit them together.
3. Your agent finds the requests that wait for it. It answers, asks, or does the work. For each change it commits and links the commit to the request.
4. The agent marks a request "Ready for review". You look at the linked commits and verify or reopen the request.
5. When you are finished, you close the review.

You can have several open reviews of one repository at the same time, for example one per release. Each review has its own requests.

## Pages

| Page | What it tells you |
|---|---|
| [Getting started](getting-started.md) | Sign in with GitHub, connect a repository, start a review, and connect your agent |
| [Connect your agent](connect-your-agent.md) | Create a token, configure the MCP server, install the skill, and give your agent a review |
| [Repositories](repositories.md) | Give Slopmower access, connect a repository, get new commits, and start a review |
| [Reviewing](reviewing.md) | The review screen, its header, and the review tour |
| [Browse the code](browse-code.md) | The file tree, the file view, blame, Quick open, symbols, and declarations |
| [Comment on code](comment.md) | Comment on lines, symbols, and files, open general requests, and send comments as a batch |
| [Requests](requests.md) | Follow the requests, talk with the agent, approve plans, verify, reopen, carry over, and close a review |
| [Pull requests](pull-requests.md) | Why Slopmower watches pull requests, the Slopmower check, and branch protection |
| [Keyboard shortcuts](keyboard-shortcuts.md) | Every shortcut of the review |
| [Settings](account.md) | API tokens, the skill, your plan and its limits, the review tour, and the deletion of your data |
| [Run it locally](local-run.md) | Start Slopmower with Docker on your computer and review repositories on your disk, without GitHub |
| [Support](support.md) | Get help, ask about plans and billing, and report a security problem |
| [Privacy policy](privacy.md) | The data that Slopmower keeps, why, for how long, the cookies, and your rights |
| [Terms of service](terms.md) | The rules for the use of the hosted service |
