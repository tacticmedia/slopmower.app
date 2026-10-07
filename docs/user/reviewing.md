# Reviewing

This page shows how to comment on code, work with your agent, and finish a review. The review view works like a GitHub pull request review, but it shows the full files at the review commit.

## Comment on code

You can comment on a line, a range of lines, a symbol, or a file. Each comment opens a change request.

### With the mouse

| Target | What to do |
|---|---|
| One line | Move the pointer over the line number and click the blue **+**. The comment box opens below the line. |
| A range of lines | Drag from the **+**. Or select the lines first: drag over the line numbers, or click the first line number and shift-click the last one. Then click the **+** on the last selected line. The comment box opens below the last line. |
| A symbol | Open **Symbols** in the file header and choose a class, method, function, or other symbol. Or select exactly the lines of a symbol. The comment then belongs to the symbol, and it follows the symbol when the code moves. |
| A file | Click **Comment on file** in the file header. |

### With the keyboard

| Target | What to do |
|---|---|
| Lines | Use **Comment on lines** in the file header. Type the start line and the end line, then click **Comment**. For one line, type the same number twice. |
| A symbol | Open **Symbols** in the file header, move to a symbol with the arrow keys, and press Enter. |
| A file | Move to **Comment on file** with Tab and press Enter. |

The comment box receives the focus. Type your comment. Comments use GitHub-flavoured Markdown. Press Ctrl+Enter (Cmd+Enter on macOS) to send it with its submit button, for example **Add single comment** or **Reply**. In a box with **Cancel**, Escape cancels it.

If a comment cannot be saved, its box opens again with your text, the error, and the selected lines.

Symbols are available for PHP files. A symbol range includes the doc comment above the symbol. When a file has no symbols, or they could not be loaded, **Symbols** is unavailable, and its tooltip says why.

## Open a general request

A general request belongs to the whole review and names no file, line, or symbol, for example "Add a full-screen focus mode". Open **Requests** and click **New request**. Type the request and click **Add single comment**, or **Start a review** to keep it as a draft.

The list shows **General** in place of a location. No file view shows a general request, and the path filter never matches it.

## Send comments one by one or as a batch

- **Add single comment** sends the comment at once. The agent sees it the next time it checks for updates.
- **Start a review** keeps the comment as a draft. Only you can see drafts. Add more comments with **Add review comment**, then click **Finish your review**. Type an optional summary and click **Submit review**. The agent sees all comments of the batch together.

You can edit or delete a draft before you submit it. **Pending drafts** on the **Requests** tab lists all your drafts: new requests and replies. Each draft links to its lines or to the request that it replies to.

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

**Waiting for you** shows the requests where it is your turn: the agent asked a question, proposed a solution, finished, or declined.

## Talk with the agent

Each request has a conversation. The agent can ask a question before it changes code. Its question shows under **Waiting for you**. Type your answer in the reply box. Your reply hands the turn back to the agent.

New replies from the agent show in the open request without a reload.

## Ask for a plan first

Every comment box of a new request has the checkbox **Ask for a plan first**. It is checked by default. You cannot change it after the request is open. The request shows **Plan required**.

With the checkbox, the agent proposes a solution before it commits: a plan, and for a straightforward change a diff. An agent can also propose on any other request, for example when the request is unclear. The list of requests shows **Plan to approve** while a proposal waits for you.

On the request, the proposal shows the plan and the changes of each file of the diff. **Download .diff** saves the diff as a file.

- **Approve** lets the agent do the work as proposed.
- **Request changes** sends the proposal back. Type a note that says what to change. The agent proposes again.

A newer proposal replaces the older one, which shows **Superseded**. A proposal of a claim that ended, for example after **Unassign**, shows **Withdrawn**.

## Linked commits

The agent links each commit to the requests that it covers. One commit can cover several requests. The request shows each linked commit with its state:

- **Pending**: Holistic Review does not have the commit yet. For a GitHub repository, the agent must push. For a local repository, the commit must be on a branch of the connected repository.
- **Available**: Holistic Review has the commit. Click it to see its changes on the **Changes** tab.

On the **Changes** tab, a commit with more than 20 changed files opens only the files that hold the code of the request. Click the arrow of a file to show or hide its changes.

A linked commit does not finish a request. The agent marks the request **Ready for review** when it is finished.

## Verify, reopen, or decline

On a request that is ready for review:

- **Resolve conversation** verifies the request at once.
- **Reopen** sends the request back to the agent. Type a note that says what is still wrong.

On any open, reopened, or in-progress request:

- **Close as won't do** declines the request. Type a reason of at least 20 characters.

When the agent declines a request, it must give a reason with evidence that you can check. You then verify the decision with **Resolve conversation**, or reopen the request.

If an agent claimed a request and stopped working on it, click **Unassign**. The request goes back to its status before the claim.

## Go to a declaration

In a PHP file, hold Cmd (Ctrl outside macOS) and click a name to open its declaration at the review commit. The name is underlined while you hold the key. A plain click still selects text. This works for class names in any position, `Type::method()`, `Type::CONSTANT`, `Type::$property`, enum cases, `self`, `static`, and `parent`, function calls, and constants.

With the keyboard, move to **Go to declaration** in the file header with Tab, type a name, and press Enter. The name resolves like PHP code in the open file, with its namespace and its `use` imports. Start a fully qualified name with `\`, for example `\App\Billing\Invoice`, and a name in the namespace of the file with `namespace\`. A member takes the form `Invoice::total` or `Invoice::$lines`.

- One declaration opens at its first line, below any doc comment, and that line is selected. The file opens when the declaration is in another file.
- Several declarations open a menu. Choose one.
- **No declaration in this commit** means that the review commit does not declare the name, for example a vendor class that is not committed, or that the symbol index has not reached the file yet.

The browser's Back button returns to the line that you clicked. A method call on a variable, such as `$invoice->total()`, has no target, because it needs the type of the variable.

## Quick open

Click **Quick open** in the review header, or press Cmd+K (Ctrl+K outside macOS), and type a part of a file path or of a symbol name. The list shows the files and the PHP symbols of the review commit that contain your text, in any case. A name equal to your text comes first, then names that start with it, then names that contain it, then paths that contain it. Shorter paths come first within each group.

- Move through the list with the arrow keys and press Enter, or click a hit.
- A file opens at its start. A symbol opens its file at the first line of its declaration, below any doc comment, and that line is selected.
- Escape closes the dialog. It does not leave focus mode.

The shortcut also works in focus mode. It does nothing while you type in a comment box or another text field. **Index in progress** means that the symbol index has not finished, so some files and symbols are not in the list yet. **Index failed: hits may be missing** means that the symbol index of the review failed, so the list can stay incomplete.

## Wrap long lines

**Wrap lines** in the file header wraps long lines in the code and in the changes of linked commits, and your browser remembers the choice.

## Viewed files

Select **Viewed** in the file header when you are finished with a file. The file tree marks viewed files. The marks stay for the whole review, because the code of the review never changes.

## Focus mode

Click **Focus** in the file header to show only the file tree and the file. They fill the window, and each scrolls on its own. To leave, click **Focus** again or press Escape. Escape in a comment box does not leave focus mode.

Focus mode stays on when you open another file or reload the page. The choice is saved in your browser. On a narrow screen, focus mode only hides the headers.

## Outdated requests

A request is **Outdated** when its code changed on the default branch, or when Holistic Review cannot find the code there any more. The review view always shows the code at the review commit, so the request stays at its original place. The label tells you that the code on the default branch is different.

## Carry over open requests

When the code moved on, start a new review at the later commit and take the open requests of an older review with you. On the repository page, under **Start a review**, pick the older review in **Carry over open requests from**, then click **Start review**.

Holistic Review finds the code of each open or reopened request at the new commit and opens a copy of the request in the new review. A list then shows every request of the older review with its outcome:

- **Carried**: the copy points at the same code, at its new place.
- **Carried, the code changed**: the copy points at the changed code, and its first comment says that the code changed.
- **Carried to the whole file, the code was not found**: the copy is a comment on the file, and its first comment shows the old code.
- **Not carried**: the request stays in the older review only. The reason is its status, for example **in progress**, or that its file is gone.

A copy shows **Carried from the source request**, a link to the request in the older review. The older review does not change. Close it when you no longer need it.

The UI offers carry-over when a review starts. A review that is already open cannot take requests from another review in the UI.

## Close a review

When you are finished, click **Close review** in the review header. You can add a note. If you have drafts, Holistic Review warns you, because closing deletes them.

A closed review is read-only. Nobody can comment, change a status, or link commits. The agent gets `review_closed` and stops. Closed reviews are hidden from the lists. Select **Show closed** to see them. A closed review cannot be opened again. Start a new review instead.
