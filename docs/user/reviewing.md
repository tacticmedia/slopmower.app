# Reviewing

This page shows how to comment on code, work with your agent, and finish a review. The review view works like a GitHub pull request review, but it shows the full files at the review commit.

## Comment on code

You can comment on a line, a range of lines, a symbol, or a file. Each comment opens a change request.

### With the mouse

| Target | What to do |
|---|---|
| One line | Move the pointer over the line number and click the blue **+**. The comment box opens below the line. |
| A range of lines | Drag from the **+** or over the line numbers. Or click the first line number and shift-click the last one. The comment box opens below the last line. |
| A symbol | Open **Symbols** in the file header and choose a class, method, function, or other symbol. Or select exactly the lines of a symbol. The comment then belongs to the symbol, and it follows the symbol when the code moves. |
| A file | Click **Comment on file** in the file header. |

### With the keyboard

| Target | What to do |
|---|---|
| Lines | Use **Comment on lines** in the file header. Type the start line and the end line, then confirm. For one line, type the same number twice. |
| A symbol | Open **Symbols** in the file header, move to a symbol with the arrow keys, and press Enter. |
| A file | Move to **Comment on file** with Tab and press Enter. |

The comment box receives the focus. Type your comment. Comments use GitHub-flavoured Markdown.

Symbols are available for PHP files when the index of the review is complete. A symbol range includes the doc comment above the symbol.

## Send comments one by one or as a batch

- **Add single comment** sends the comment at once. The agent sees it the next time it checks for updates.
- **Start a review** keeps the comment as a draft. Only you can see drafts. Add more comments, then click **Finish your review**. Type an optional summary and click **Submit review**. The agent sees all comments of the batch together.

You can edit or delete a draft before you submit it.

## Follow the requests

Open **Requests** to see all requests of the review. You can filter by status, by path, and by outdated code.

| Status | Meaning |
|---|---|
| Open | Nobody works on the request |
| In progress | An agent claimed the request. The list shows how long ago. |
| Ready for review | The agent is finished and waits for you |
| Verified | You accepted the result |
| Reopened | You rejected the result. The agent works on it again. |
| Won't do | The request was declined, with a reason |

**Waiting for you** shows the requests where it is your turn: the agent asked a question, finished, or declined.

## Talk with the agent

Each request has a conversation. The agent can ask a question before it changes code. Its question shows under **Waiting for you**. Type your answer in the reply box. Your reply hands the turn back to the agent.

New replies from the agent show in the open request without a reload.

## Linked commits

The agent links each commit to the requests that it covers. One commit can cover several requests. The request shows each linked commit with its state:

- **Pending**: Holistic Review does not have the commit yet. For a GitHub repository, the agent must push. For a local repository, the commit must be on a branch of the connected repository.
- **Available**: Holistic Review has the commit. Click it to see its changes.

A linked commit does not finish a request. The agent marks the request **Ready for review** when it is finished.

## Verify, reopen, or decline

On a request that is ready for review:

- **Resolve conversation** verifies the request. You can add a note.
- **Reopen** sends the request back to the agent. Type a note that says what is still wrong.

On any open, reopened, or in-progress request:

- **Close as won't do** declines the request. Type a reason of at least 20 characters.

When the agent declines a request, it must give a reason with evidence that you can check. You then verify the decision with **Resolve conversation**, or reopen the request.

If an agent claimed a request and stopped working on it, click **Unassign**. The request goes back to its status before the claim.

## Viewed files

Select **Viewed** in the file header when you are finished with a file. The file tree marks viewed files. The marks stay for the whole review, because the code of the review never changes.

## Outdated requests

A request is **Outdated** when its code changed on the default branch, or when Holistic Review cannot find the code there any more. The review view always shows the code at the review commit, so the request stays at its original place. The label tells you that the code on the default branch is different.

## Close a review

When you are finished, click **Close review** in the review header. You can add a note. If you have drafts, Holistic Review warns you, because closing deletes them.

A closed review is read-only. Nobody can comment, change a status, or link commits. The agent gets `review_closed` and stops. Closed reviews are hidden from the lists. Select **Show closed** to see them. A closed review cannot be opened again. Start a new review instead.
