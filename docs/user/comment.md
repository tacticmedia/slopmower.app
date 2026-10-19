# Comment on code

You can comment on a line, a range of lines, a symbol, or a file. Each comment opens a change request. A general request belongs to the whole review. Then follow the requests as [Requests](requests.md) shows.

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

The comment box receives the focus. Type your comment. Comments use GitHub-flavoured Markdown. Press Ctrl+Enter (Cmd+Enter on macOS) to send it with its submit button, for example **Add single comment** or **Reply**. In a box with **Cancel**, Escape cancels it.

Every comment box of a new request has the checkbox **Ask for a plan first**. It is checked by default, so the agent proposes a plan before it commits. See [Ask for a plan first](requests.md#ask-for-a-plan-first).

If a comment cannot be saved, its box opens again with your text, the error, and the selected lines.

## Open a general request

A general request belongs to the whole review and names no file, line, or symbol, for example "Add a full-screen focus mode". Open **Requests** and click **New request**. Type the request and click **Add single comment**, or **Start a review** to keep it as a draft.

The list shows **General** in place of a location. No file view shows a general request, and the path filter never matches it.

## Title and description

A request has a description and an optional title, like a GitHub issue. A comment box that opens a request has a one-line **Title** field above the **Description**. Leave the title empty if the first line of the description says enough. In the **Title** field, Enter moves to the **Description**. A reply has no title.

The heading of the request, its row in the list of requests, its thread in the code, and the browser tab show the title. A request without a title shows the first line of its description as plain text, without Markdown syntax, and its heading is dimmed. Your agent gives each untitled request a title from its description before it starts work, and it gives each of its suggestions a title.

To set or change the title, open the request and click **Edit**, or **Add a title** when it has none. Type the title, then press Enter or click **Save**. Escape or **Cancel** keeps the old title. A title is one line of at most 200 characters. You can change it in every status while the review is open, but you cannot remove it. The description does not change: reply in the conversation to add to it.

## Send comments one by one or as a batch

- **Add single comment** sends the comment at once. The agent sees it the next time it checks for updates.
- **Start a review** keeps the comment as a draft. Only you can see drafts. Add more comments with **Add review comment**, then click **Finish your review**. Type an optional summary and click **Submit review**. The agent sees all comments of the batch together.

You can edit or delete a draft before you submit it. **Pending drafts** on the **Requests** tab lists all your drafts: new requests and replies. Each draft links to its lines or to the request that it replies to.
