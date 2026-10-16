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

Symbols are available for PHP, Twig, JavaScript, JSX, TypeScript, TSX, Python, Go, Rust, Java, C#, Ruby, Bash, Scala, Zig, and Lua files. In Twig, a block and a macro are symbols, for example `content` and `row()`, and a `{## #}` documentation comment above the tag is part of the range. A block inside `{% embed %}` is not a symbol. A Zig file with an empty `struct {}`, `enum {}`, `union {}`, or `opaque {}` has no symbols, because the Zig grammar refuses an empty container. A symbol range includes the doc comment above the symbol, and the decorators, annotations, or attributes of its declaration. A Python docstring is inside the range. In languages other than PHP, Rust, and Ruby, a symbol name joins the names of its class, interface, struct, enum, object, or namespace with `.`, for example `InvoiceTotals.total()`. A Java or Scala package and a C# namespace are not part of the name. A Go method takes the name of its receiver type, for example `Totals.Sum()`, and a Lua function `M.total` or method `M:count` takes the name of its table, `M.total()` and `M.count()`. Rust and Ruby join names with `::`, for example `Total::new()` and `Billing::InvoiceTotals::total()`, and a Rust method of a trait implementation is `<Total as From<i64>>::from()`. When a file has no symbols, or they could not be loaded, **Symbols** is unavailable, and its tooltip says why.

## Open a general request

A general request belongs to the whole review and names no file, line, or symbol, for example "Add a full-screen focus mode". Open **Requests** and click **New request**. Type the request and click **Add single comment**, or **Start a review** to keep it as a draft.

The list shows **General** in place of a location. No file view shows a general request, and the path filter never matches it.

## Title and description

A request has a description and an optional title, like a GitHub issue. A comment box that opens a request has a one-line **Title** field above the **Description**. Leave the title empty if the first line of the description says enough. A reply has no title.

The heading of the request, its row in the list of requests, its thread in the code, and the browser tab show the title. A request without a title shows the first line of its description, and its heading is dimmed. Your agent gives each untitled request a title from its description before it starts work, and it gives each of its suggestions a title.

To set or change the title, open the request and click **Edit**, or **Add a title** when it has none. Type the title, then press Enter or click **Save**. Escape or **Cancel** keeps the old title. A title is one line of at most 200 characters. You can change it in every status while the review is open, but you cannot remove it. The description does not change: reply in the conversation to add to it.

## Send comments one by one or as a batch

- **Add single comment** sends the comment at once. The agent sees it the next time it checks for updates.
- **Start a review** keeps the comment as a draft. Only you can see drafts. Add more comments with **Add review comment**, then click **Finish your review**. Type an optional summary and click **Submit review**. The agent sees all comments of the batch together.

You can edit or delete a draft before you submit it. **Pending drafts** on the **Requests** tab lists all your drafts: new requests and replies. Each draft links to its lines or to the request that it replies to.

## The review header

The header shows the title of the review, its ref, and its commit, with these buttons:

- **Quick open** finds a file or a symbol.
- **Agent prompts** shows the review ID, the agent prompt, and the review prompt, each with a copy button, and the link **Connect your agent**.
- **Finish your review** shows while you have drafts.
- **Review actions**, the button with three dots, has **Carry over requests** and **Close review** while the review is open, and **Delete review**.

While the symbol index runs, a progress bar shows below the title. If the index fails, the reason shows there.

## Follow the requests

Open **Requests** to see the requests of the review. Each row shows the status icon and the title, the labels **Waiting for you**, **Plan to approve**, **Suggestion**, and **Outdated** when they apply, and a muted line with the location, the author, and the agent that works on it. At the right, a row shows its comments, its linked commits, and when it changed last.

- **Open** lists the requests that wait for you or for the agent. It is the default. **Closed** lists the verified requests and the requests that you closed as won't do. The two counts are the totals of the review.
- **Status** shows one status only. **Filters** has **Outdated** and **Suggestions**, the suggestions of the agent that wait for your decision. **Sort** orders the list by the last change (the default), by age, oldest first, or by path.
- Type a path prefix and click **Filter** to show only the requests on files below it.

The address of the page keeps the filters, so a reload or a bookmark shows the same list. When no request matches, **Clear the filters** shows the open requests again.

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

A request opens like a GitHub issue. The header shows the title, the status, who opened the request, and the number of comments.

Below the header, a bar holds the buttons that change the request, for example **Resolve conversation** and **Reopen**. On a long conversation, the bar stays at the top of the window and also shows the status and the title. A request of a closed review has no bar.

One line below the bar tells you what happens next, for example "The agent finished. Check the changes, then resolve the conversation or reopen it." When it is your turn, the line is highlighted.

The sidebar is at the right of the conversation. On a narrow screen, it is above the conversation.

| Section | Content |
|---|---|
| Assignee | The agent that works on the request, and for how long. Else **No one**. |
| Plan | The state of the plan, and **Ask for a plan first** or **Don't ask for a plan**. See [Ask for a plan first](#ask-for-a-plan-first). |
| Location | The lines, the file, or the symbol, or **General**, where the code is on the default branch, and **Outdated**. |
| Development | The linked pull requests and the number of linked commits. |
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

On the **Changes** tab, a commit with more than 20 changed files opens only the files that hold the code of the request. Click the arrow of a file to show or hide its changes.

A linked commit does not finish a request. The agent marks the request **Ready for review** when it is finished.

## Pull requests

When the agent opened a pull request for its work, it links the pull request to the requests that it covers. The **Development** section of the request lists its pull requests, and the conversation shows each link. Click a pull request to open it in a new tab. The agent can remove a link that it made by mistake. The conversation shows the removal too.

For a GitHub repository, each pull request shows its state on GitHub: Open, Draft, Merged, or Closed. The **Development** section also shows its title. When the pull request changes on GitHub, for example when it is merged, the label changes without a reload. A pull request shows no state when the repository is local, when Slopmower has not read it yet, when GitHub does not find it, or when the GitHub App installation has not accepted the permission Pull requests: read. When the installation accepts it, Slopmower reads each linked pull request that shows no state. Slopmower only reads pull requests. On GitHub it writes only the Slopmower check.

A pull request link does not change the status, and the request does not wait for you because of it. The agent opens a pull request only when you or the instructions of your repository ask for it.

### The Slopmower check

For a GitHub repository, Slopmower sets your check **Slopmower (your login)**, with your GitHub login, on the newest commit of each open pull request that one of your requests links. Each Slopmower user has a separate check, so the requests of another user never change yours. The check stays in progress while a linked request is unfinished. It passes when each linked request is verified, declined by you, or in a closed review. Everyone who can see the pull request can read the check: the headlines of the linked requests, their states, and the links to them in Slopmower. The headline is the title of a request, or the first line of its description while it has no title, cut to 80 characters. The check never shows the rest of a request.

Each unfinished request on a line or file of the code shows as an annotation with its headline, its state, and its link: a warning while the agent works on it, and a notice while it waits for you. **Files changed** shows the annotations on the files that the pull request changes, and the page of the check shows all of them. The summary of the check lists the linked requests per review, with a link to each. A general request has no annotation. A pull request from a fork gets no annotations, because Slopmower fetches only the branches of the repository. **Re-run** on GitHub sets the check again.

The check needs the permission Checks: write. When your installation of the GitHub App does not have it yet, accept the new permission on GitHub. Slopmower then sets your check on each open pull request that one of your requests links.

By default, only the pull requests that your requests link get your check. To check every pull request of a GitHub repository, open the repository in Slopmower and switch on **Check every pull request**. A pull request without your linked requests then gets a neutral check when it is opened, reopened, or pushed to, so your check does not block its merge.

To block the merge until your linked requests are finished, first switch on **Check every pull request**, because a required check that never runs blocks every pull request. Then make your check required with a branch protection rule. GitHub lists the check after Slopmower has set it on a pull request of the repository.

1. On GitHub, open **Settings** of the repository, then **Branches**, and click **Add classic branch protection rule**.
2. Enter the default branch as **Branch name pattern**.
3. Select **Require status checks to pass before merging**, search for **Slopmower (your login)**, and select it.
4. Set the source of the check to the Slopmower app, so that no other app or person can set it.
5. Click **Create**.

A ruleset can require the check too, but it accepts an app as the source only when the app has the permission Statuses: write, which Slopmower does not request. In a ruleset, choose **any source** for the check.

When you rename your GitHub login, your check gets the new name after your next sign-in. Replace the required check in the rule with the new name. Until then, a Slopmower user who takes your old login has a check with the same name as yours.

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

The list shows the files and the symbols of the review commit that match your text. A name equal to your text comes first, then names that start with it, then names whose word starts match it, then names that contain it, then paths that contain it. Within each group, shorter names come first, then shorter paths.

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

When you scroll into the code, the toolbar stays at the top of the window, with the path and **Top** above it. **Top** goes back to the top of the page. In a file with symbols, the declaration lines of the class, the module, the function, and the Twig block around the top line stay below the toolbar. Click one to go to it.

On a narrow screen, the tree is above the file and scrolls away with the page.

## Blame

Click **Blame** in the toolbar of a file to see who last changed each line, up to the commit of the review. **Code** goes back. The line in the address stays selected.

A block at the left of the code covers the lines of one commit: the age of the commit, its author, and the first line of its message. Point at a block for the full date and the commit SHA. The colour bar shows the age, from light for old commits to dark for the last days, as the legend **Older** to **Newer** in the toolbar shows.

When the repository has a `.git-blame-ignore-revs` file, the commits that it names are skipped, as on GitHub, and a note says so. The blame view has no comments: go back to **Code** to comment.

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

A closed review is read-only. Nobody can comment, change a status, or link commits. The agent gets `review_closed` and stops. Closed reviews are hidden from the lists. Select **Show closed** to see them. A closed review cannot be opened again. Start a new review instead.
