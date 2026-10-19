# Requests

A comment on the code opens a change request, and so does a general request. Your agent works on the requests, and you check its work. The **Requests** tab of a review lists the requests, and each request has its own page. To open a request, see [Comment on code](comment.md).

<!-- On this page -->

## Follow the requests

Open **Requests** to see the requests of the review. Each row shows the status icon and the title, the labels **Waiting for you**, **Plan to approve**, **Suggestion**, and **Outdated** when they apply, and a muted line with the location, the author, and the agent that works on it. At the right, a row shows its comments, its linked commits, and when it changed last.

- **Open** lists the requests that wait for you or for the agent. It is the default. **Closed** lists the verified requests and the requests that you closed as won't do. The two counts are the totals of the review.
- **Status** shows one status only. **Filters** has **Outdated** and **Suggestions**, the suggestions of the agent that wait for your decision. **Sort** orders the list by the last change (the default), by age, oldest first, or by path.
- Type a path prefix and click **Filter** to show only the requests on files below it.

The address of the page keeps the filters, so a reload or a bookmark shows the same list. When no request matches, **Clear the filters** shows the open requests again.

## Statuses

| Status | Meaning |
|---|---|
| Open | Nobody works on the request |
| In progress | An agent claimed the request. The list shows how long ago. |
| Ready for review | The agent is finished and waits for you |
| Verified | You accepted the result |
| Reopened | You rejected the result. The agent works on it again. |
| Won't do | The request was declined, with a reason |

**Waiting for you**, on **Open**, shows the requests where it is your turn: the agent suggested a change, asked a question, proposed a solution, finished, or declined.

## The request page

A request opens like a GitHub issue. The header shows the title, the status, who opened the request, and the number of comments when it has any.

Below the header, a bar holds the buttons that change the request, for example **Resolve conversation** and **Reopen**. On a long conversation, the bar stays at the top of the window and also shows the status and the title. A request of a closed review has no bar.

One line below the bar tells you what happens next, for example "The agent finished. Check the changes, then resolve the conversation or reopen it." When it is your turn, the line is highlighted.

The sidebar is at the right of the conversation. On a narrow screen, it is above the conversation. The **Changes** tab uses the full width of the window. On a wide screen, it has no sidebar.

| Section | Content |
|---|---|
| Assignee | The agent that works on the request, and for how long. Else **No one**. |
| Plan | The state of the plan, and **Ask for a plan first** or **Don't ask for a plan**. See [Ask for a plan first](#ask-for-a-plan-first). |
| Location | The lines, the file, or the symbol, or **General**, whether the code is still the same on the default branch, and **Outdated**. |
| Development | The linked pull requests and the number of linked commits. See [Pull requests](pull-requests.md). |
| Suggested by | The agent that suggested the request. |

The **Plan** section shows one of these values:

| Value | Meaning |
|---|---|
| Required before code changes | You asked for a plan, and the agent has not proposed one. |
| Not required | You did not ask for a plan. |
| Waiting for your approval | The agent proposed a plan. Approve it or request changes. |
| Approved | You approved the plan. |
| Changes requested | You requested changes. The agent proposes again. |
| No plan | The work is finished, and the request has no plan. |

When the work is finished, the section shows the decision on the plan, or **No plan**. It never shows **Required before code changes** then.

## Talk with the agent

Each request has a conversation. The agent can ask a question before it changes code. Its question shows under **Waiting for you**. Type your answer in the reply box. Your reply hands the turn back to the agent.

New replies from the agent show in the open request without a reload.

## Ask for a plan first

Every comment box of a new request has the checkbox **Ask for a plan first**. It is checked by default. The **Plan** section of the request shows **Required before code changes**.

To change it later, for example when a small request turns out to be risky, open the request and click **Ask for a plan first** or **Don't ask for a plan** in the **Plan** section. This works while the request is open, reopened, or in progress. The thread shows the change. When you switch it on while the agent works and no proposal is approved, the agent links no commit until you approve one.

With the checkbox, the agent proposes a solution before it commits: a plan, and for a straightforward change a diff. An agent can also propose on any other request, for example when the request is unclear. **Plan to approve** shows while a proposal waits for you: in the list of requests, on the thread in the code, and in the file header of a file with such a thread.

On the request, the proposal shows the plan and the changes of each file of the diff. **Download .diff** saves the diff as a file.

- **Approve** lets the agent do the work as proposed.
- **Request changes** sends the proposal back. Type a note that says what to change. The agent proposes again.

An older proposal keeps its decision: **Approved** or **Changes requested**. An older proposal without a decision shows **Superseded** when a newer one replaced it, or **Withdrawn** when its claim ended, for example after **Unassign**.

## Agent suggestions

Your agent can review the code too. Click **Agent prompts** in the review header, copy the review prompt, **Review the code of Slopmower review <id> and suggest changes**, and give it to the agent, or ask the agent to review a part of a repository, for example "Review the src/Mcp directory with Slopmower and suggest changes". Without a review, the agent starts one. The agent changes no code. It opens one suggestion for each thing that it would change, on a line, a range of lines, a symbol, a file, or the whole review.

Each suggestion shows at once, marked **Suggestion**: in the list of requests, in the file header, and on its thread in the code. **Suggestions** on the **Requests** tab lists the suggestions that wait for you. You can discuss a suggestion in its thread before you decide.

- **Accept** makes the suggestion an ordinary request that waits for the agent. **Ask for a plan first** is checked by default, so the agent proposes a plan before it commits.
- **Dismiss** closes the suggestion as won't do. You can type a note that says why.

To let the agent work on the accepted suggestions, give it the agent prompt of **Agent prompts**, **Work on Slopmower review <id>**. The agent works on the accepted suggestions and on your own requests, never on a suggestion that waits for you. **Reopen** on a dismissed suggestion accepts it.

## Linked commits

The agent links each commit to the requests that it covers. One commit can cover several requests. The request shows each linked commit with its state:

- **Pending**: Slopmower does not have the commit yet. For a GitHub repository, the agent must push. For a local repository, the commit must be on a branch of the connected repository.
- **Available**: Slopmower has the commit. Click it to see its changes on the **Changes** tab.

On the **Changes** tab, a commit with more than 20 changed files opens only the files that hold the code of the request. Click the arrow of a file to show or hide its changes. **Wrap lines** in the file header of the Code tab also wraps the changes.

A linked commit does not finish a request. The agent marks the request **Ready for review** when it is finished. When the agent opened a pull request for the work, it links the pull request too. See [Pull requests](pull-requests.md).

## Verify, reopen, or decline

On a request that is ready for review:

- **Resolve conversation** verifies the request at once.
- **Reopen** sends the request back to the agent. Type a note that says what is still wrong.

On any open, reopened, or in-progress request:

- **Close as won't do** declines the request. Type a reason of at least 20 characters.

When the agent declines a request, it must give a reason with evidence that you can check. You then verify the decision with **Resolve conversation**, or reopen the request.

If an agent claimed a request and stopped working on it, click **Unassign**. The request goes back to its status before the claim.

## Outdated requests

A request is **Outdated** when its code changed on the default branch, or when Slopmower cannot find the code there any more. The review view always shows the code at the review commit, so the request stays at its original place. The label tells you that the code on the default branch is different.

## Carry over open requests

When the code moved on, start a new review at the later commit and take the open requests of an older review with you. On the repository page, under **Start a review**, pick the older review in **Carry over open requests from**, then click **Start review**.

An open review can also take the open requests of another review of the repository, for example after the agent released a request of that review, or you clicked **Unassign** on it. Click **Review actions** in the review header, then **Carry over requests**, pick the review in **Carry over open requests from**, then click **Carry over**. The list has the open and the closed reviews of the repository. A closed review shows **(closed)**.

Slopmower finds the code of each open or reopened request at the commit of the review that takes the requests, and opens a copy of the request in that review. A list then shows every request of the other review with its outcome:

- **Carried**: the copy points at the same code, at its new place.
- **Carried, the code changed**: the copy points at the changed code, and its first comment says that the code changed.
- **Carried to the whole file, the code was not found**: the copy is a comment on the file, and its first comment shows the old code.
- **Carried earlier**: the review has a copy of the request from an earlier carry-over. Nothing changes.
- **Not carried**: the request stays in the other review only. The reason is its status, for example **in progress**, or that its file is gone.

A copy shows **Carried from the source request**, a link to the request in the other review. The other review does not change. Close it when you no longer need it.

## Close a review

When you are finished, click **Review actions** in the review header, then **Close review**. You can add a note. If you have drafts, Slopmower warns you, because closing deletes them.

A closed review is read-only. Nobody can comment, change a status, or link commits. The agent gets `review_closed` and stops. Closed reviews are hidden from the lists. Select **Show closed** to see them. While a repository has no open review, click **Show closed reviews** on its page. A closed review cannot be opened again. Start a new review instead.
