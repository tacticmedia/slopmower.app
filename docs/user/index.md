# Help center

## The absolute TL;DR written by a human, for humans

> Slopmower is a UI bridge between your locally running AI agent and you. It lets you ask questions, comment on, and steer the entire codebase. Let me explain. 
>
> Whether hand-chiselled, AI-slopped, or somewhere in between, every codebase gets murky over time. Think of Slopmpower as a codebase-level code review. Not individual commits or PRs - the whole lot. 
>
> The main use cases are: (a) you inherit a legacy codebase and intend to use your coding agent to do something about it, and (b) you let your agent work for a while - it looks like it works, but then you open the code and [wtfs per minute](https://www.osnews.com/story/19266/wtfsm/) skyrocket.
>
> How do you communicate with the agent? Make a list of things you found? What, using Markdown? How do you refer to the exact spot? Wasted context. How do you keep track of things? Wasted human lifetime. It's a lot. Once you want to talk about more than one topic, you need to treat each topic as a conversation on an individual timeline. 
> 
> That's where Slopmower comes in.
> 
> It tries to mimic GitHub's pull request experience, letting you float through your codebase and type your thoughts. It exposes an MCP endpoint you point your local coding agent at, and the agent uses it to find your comments and take action, whether that is answering a question, proposing a code change, or anything else. 
> 
> You can even ask your agent to review the code for you and record findings in Slopmower for you to review. It’s all up to you.
> 
> It's an absolute experiment. A different workflow. I spent over 20 years writing software nearly every day. I still read code. Slopmower is my attempt to fix the problem I see emerging: too much code, too hard to steer. Maybe I’m doing it wrong, but I’m not ready to let go of the code control and trust a probability machine to do a good job. 
> 
> Try it, and please let me know what you think.
>
> &mdash; Jan Klan, someone who still reads the code
---

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
