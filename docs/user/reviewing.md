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

Every comment box of a new request has the checkbox **Ask for a plan first**. It is checked by default. The request shows **Plan required**.

To change it later, for example when a small request turns out to be risky, open the request and click **Ask for a plan first** or **Don't ask for a plan**. This works while the request is open, reopened, or in progress. The thread shows the change. When you switch it on while the agent works and no proposal is approved, the agent links no commit until you approve one.

With the checkbox, the agent proposes a solution before it commits: a plan, and for a straightforward change a diff. An agent can also propose on any other request, for example when the request is unclear. **Plan to approve** shows while a proposal waits for you: in the list of requests, on the thread in the code, and in the file header of a file with such a thread.

On the request, the proposal shows the plan and the changes of each file of the diff. **Download .diff** saves the diff as a file.

- **Approve** lets the agent do the work as proposed.
- **Request changes** sends the proposal back. Type a note that says what to change. The agent proposes again.

An older proposal keeps its decision: **Approved** or **Changes requested**. An older proposal without a decision shows **Superseded** when a newer one replaced it, or **Withdrawn** when its claim ended, for example after **Unassign**.

## Linked commits

The agent links each commit to the requests that it covers. One commit can cover several requests. The request shows each linked commit with its state:

- **Pending**: Holistic Review does not have the commit yet. For a GitHub repository, the agent must push. For a local repository, the commit must be on a branch of the connected repository.
- **Available**: Holistic Review has the commit. Click it to see its changes on the **Changes** tab.

On the **Changes** tab, a commit with more than 20 changed files opens only the files that hold the code of the request. Click the arrow of a file to show or hide its changes.

A linked commit does not finish a request. The agent marks the request **Ready for review** when it is finished.

## Pull requests

When the agent opened a pull request for its work, it links the pull request to the requests that it covers. The header of the request lists its pull requests, and the conversation shows each link. Click a pull request to open it in a new tab. The agent can remove a link that it made by mistake. The conversation shows the removal too.

A pull request link does not change the status, and the request does not wait for you because of it. Holistic Review stores the address only. It does not read the pull request from GitHub. The agent opens a pull request only when you or the instructions of your repository ask for it.

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

Click **Quick open** in the review header, or press Cmd+K (Ctrl+K outside macOS), and type a part of a file path or of a symbol name. You can also type the starts of the words of a name: `CE` or `ComEx` finds `CommandExecutor`, and `ReqDec` finds `RequestDecider`. A word starts after a character such as `_` or `.`, at a capital letter after a lower-case letter or a digit, and at the last capital letter before a lower-case letter, so `HTTPClient` has the words `HTTP` and `Client`. Case does not matter.

The list shows the files and the PHP symbols of the review commit that match your text. A name equal to your text comes first, then names that start with it, then names whose word starts match it, then names that contain it, then paths that contain it. Within each group, shorter names come first, then shorter paths.

- Move through the list with the arrow keys and press Enter, or click a hit.
- A file opens at its start. A symbol opens its file at the first line of its declaration, below any doc comment, and that line is selected.
- Escape closes the dialog.

The shortcut does nothing while you type in a comment box or another text field. **Index in progress** means that the symbol index has not finished, so some files and symbols are not in the list yet. **Index failed: hits may be missing** means that the symbol index of the review failed, so the list can stay incomplete.

## Wrap long lines

**Wrap lines** in the file header wraps long lines in the code and in the changes of linked commits, and your browser remembers the choice.

## Viewed files

Select **Viewed** in the file header when you are finished with a file. The file tree and the directory tables mark viewed files. The marks stay for the whole review, because the code of the review never changes.

## Browse the code

The **Code** tab works like the file browser of GitHub. The page scrolls: the review header moves out of the window, and the file tree and the file fill the window. The tree stays at the left with the height of the window and scrolls on its own.

- Drag the edge of the tree, or focus it and press the arrow keys, to change its width.
- **Collapse file tree** hides the tree. **Expand file tree**, before the path of the file, shows it again.
- Your browser remembers the width and whether the tree is hidden.

Without an open file, the tab shows the root directory as a table: the name, the size of each file, and a mark on viewed files. Click a directory in the table or in the tree to see its table. The chevron in the tree only opens and closes a directory. A `README.md` of the directory shows below the table.

Above a file, each part of the path links to its directory, and **Copy path** copies the path. The toolbar shows the number of lines and the size, the file commands, **Raw**, which opens the file as plain text, and buttons that copy and download the whole file.

When you scroll into the code, the toolbar stays at the top of the window, with the path and **Top** above it. **Top** goes back to the top of the page. In a PHP file, the declaration lines of the class and the function around the top line stay below the toolbar. Click one to go to it.

On a narrow screen, the tree is above the file and scrolls away with the page.

## Outdated requests

A request is **Outdated** when its code changed on the default branch, or when Holistic Review cannot find the code there any more. The review view always shows the code at the review commit, so the request stays at its original place. The label tells you that the code on the default branch is different.

## Carry over open requests

When the code moved on, start a new review at the later commit and take the open requests of an older review with you. On the repository page, under **Start a review**, pick the older review in **Carry over open requests from**, then click **Start review**.

An open review can also take the open requests of another review of the repository, for example after the agent released a request of that review, or you clicked **Unassign** on it. Click **Carry over requests** in the review header, pick the review in **Carry over open requests from**, then click **Carry over**. The list has the open and the closed reviews of the repository. A closed review shows **(closed)**.

Holistic Review finds the code of each open or reopened request at the commit of the review that takes the requests, and opens a copy of the request in that review. A list then shows every request of the other review with its outcome:

- **Carried**: the copy points at the same code, at its new place.
- **Carried, the code changed**: the copy points at the changed code, and its first comment says that the code changed.
- **Carried to the whole file, the code was not found**: the copy is a comment on the file, and its first comment shows the old code.
- **Carried earlier**: the review has a copy of the request from an earlier carry-over. Nothing changes.
- **Not carried**: the request stays in the other review only. The reason is its status, for example **in progress**, or that its file is gone.

A copy shows **Carried from the source request**, a link to the request in the other review. The other review does not change. Close it when you no longer need it.

## Close a review

When you are finished, click **Close review** in the review header. You can add a note. If you have drafts, Holistic Review warns you, because closing deletes them.

A closed review is read-only. Nobody can comment, change a status, or link commits. The agent gets `review_closed` and stops. Closed reviews are hidden from the lists. Select **Show closed** to see them. A closed review cannot be opened again. Start a new review instead.
