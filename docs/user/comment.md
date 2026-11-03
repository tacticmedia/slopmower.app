# Comment on code

You can comment on a line, a range of lines, a symbol, or a file. A new comment is a draft until you submit your feedback, and then it opens a change request. A general request belongs to the whole review. Then follow the requests as [Requests](requests.md) shows.

<!-- On this page -->

## With the mouse

| Target | What to do |
|---|---|
| One line | Move the pointer over the line number and click the blue **+**. The comment box opens below the line. |
| A range of lines | Drag from the **+**. Or select the lines first: drag over the line numbers, or click the first line number and shift-click the last one. Then click the **+** on the last selected line. The comment box opens below the last line. |
| A symbol | Open **Symbols** in the file header and choose a class, method, function, or other symbol. Or select exactly the lines of a symbol. The comment then belongs to the symbol, and it follows the symbol when the code moves. |
| A file | Click **Comment on file** in the file header. |

[Symbols](browse-code.md#symbols) lists the languages that have symbols.

## With the keyboard

| Target | What to do |
|---|---|
| Lines | Use **Comment on lines** in the file header. Type the start line and the end line, then click **Comment**. For one line, type the same number twice. |
| A symbol | Open **Symbols** in the file header, move to a symbol with the arrow keys, and press Enter. |
| A file | Move to **Comment on file** with Tab and press Enter. |

When lines are selected, Tab also reaches the **+** on the last selected line. Enter or Space opens the comment box. [Keyboard shortcuts](keyboard-shortcuts.md) lists every shortcut.

## Write the comment

The comment box receives the focus. Type your comment. Comments use GitHub-flavoured Markdown. Press Ctrl+Enter (Cmd+Enter on macOS) to click its submit button, for example **Add comment** or **Reply**. In a box with **Cancel**, Escape cancels it.

The toolbar next to the **Write** and **Preview** tabs adds Markdown: heading, bold, italic, quote, code, link, bulleted list, numbered list, and task list. A button formats the selected text, or adds the syntax at the cursor. Undo removes the change. **Preview** shows the comment as it will look, and **Write** shows the text again. When you select lines, Tab indents them by 2 spaces and Shift+Tab removes the indentation. Without a selection, Tab moves to the next control. [Keyboard shortcuts](keyboard-shortcuts.md) lists the formatting shortcuts.

Every comment box of a new request has the checkbox **Ask for a plan first**. It is checked by default, so the agent proposes a plan before it commits. See [Ask for a plan first](requests.md#ask-for-a-plan-first).

The **Type** select sets what you ask for: **Change**, the default, **Question**, or **Rule**. **Question** hides **Ask for a plan first**, because the agent changes no code. See [Request types](requests.md#request-types).

The optional **Group** field joins comments into one unit of feedback, for example several lines that need the same change. Give each comment the same group label. The field suggests the groups of your drafts, so a typo does not start a new group. A group label is one line of at most 80 characters. See [Comment groups](requests.md#comment-groups).

If a comment cannot be saved, its box opens again with your text, the error, and the selected lines.

While a comment box holds text that is not saved, Slopmower asks before it opens another page. Click **Keep editing** to stay, or **Discard** to leave without the text. The browser also asks before a reload or a closed tab.

## Open a general request

A general request belongs to the whole review and names no file, line, or symbol, for example "Add a full-screen focus mode". Open **Requests** and click **New request**. Type the request and click **Add comment**. Like a comment on code, it is a draft until you submit your feedback.

The list shows **General** in place of a location. No file view shows a general request, and the path filter never matches it.

## Title and description

A request has a description and an optional title, like a GitHub issue. A comment box that opens a request has a one-line **Title** field above the **Description**. Leave the title empty if the first line of the description says enough. In the **Title** field, Enter moves to the **Description**. A reply has no title.

The heading of the request, its row in the list of requests, its thread in the code, and the browser tab show the title. A request without a title shows the first line of its description as plain text, without Markdown syntax, and its heading is dimmed. Your agent gives each untitled request a title from its description before it starts work, and it gives each of its suggestions a title.

To set or change the title, open the request and click **Edit**, or **Add a title** when it has none. Type the title, then press Enter or click **Save**. Escape or **Cancel** keeps the old title. A title is one line of at most 200 characters. You can change it in every status while the review is open, but you cannot remove it. The description does not change: reply in the conversation to add to it.

## Submit feedback

**Add comment** keeps a new comment as a draft. Only you can see drafts. When your comments are ready, click **Submit feedback** in the review header. Type an optional summary and click **Submit feedback**. Each draft then opens a change request, and the agent sees them together the next time it checks for updates.

The dialog also says how your agent delivers the work:

- **Branch**: **Agent decides**, **Current branch**, **New branch**, which is one branch for the review, or **Worktree**, a git worktree with its own branch.
- **Pull request**: **Agent decides**, **Open one**, or **None**. It shows for a GitHub repository only, because a local repository has no pull requests.

The review keeps your choice, and the next dialog starts from it. It counts for every request of the review. When you approve a plan, you can decide on the pull request for that request, as [Ask for a plan first](requests.md#ask-for-a-plan-first) shows.

Submitting feedback does not end the review. You can add more comments and submit feedback again. To end the review, close it, as [Close a review](requests.md#close-a-review) shows.

**Send now** in the menu of a draft sends only that draft, for example a quick question. Your other drafts stay pending.

A reply in a thread is not a draft. **Reply** sends it at once, because the agent often waits for your answer. The buttons of a request, for example **Verify** or **Approve**, also act at once.

You can edit or delete a draft before you submit it. **Edit** also changes its type and its group. **Pending drafts** on the **Requests** tab lists all your drafts: new requests and replies. Each draft links to its lines or to the request that it replies to.
