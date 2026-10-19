# Reviewing

A review pins a repository to one commit, so the code of the review never changes. You read the code, comment on it, and follow the work of your agent. The review view works like a GitHub pull request review, but it shows the full files at the review commit. To start a review, see [Start a review](repositories.md#start-a-review).

## The review screen

The review has two tabs:

- **Code** shows the file tree and the files of the review commit. [Browse the code](browse-code.md) shows how to read the code, and [Comment on code](comment.md) shows how to open change requests.
- **Requests** lists the change requests of the review, and its counter shows how many there are. [Requests](requests.md) shows how to follow them and how to work with your agent.

Each request has its own page, with its conversation, its linked commits, and its pull requests. See [The request page](requests.md#the-request-page) and [Pull requests](pull-requests.md).

A closed review is read-only. A banner shows who closed it, when, and the note of the close.

## The review header

The header shows the repository, which links to the repository page, the title of the review, its ref, its commit, and who started it. It has these buttons:

- **Quick open** finds a file or a symbol. See [Quick open](browse-code.md#quick-open).
- **Agent prompts** shows the review ID, the agent prompt, and the review prompt, each with a copy button, and the link **Connect your agent**. See [Give your agent a review](connect-your-agent.md#5-give-your-agent-a-review).
- **Finish your review** shows while you have drafts. See [Send comments one by one or as a batch](comment.md#send-comments-one-by-one-or-as-a-batch).
- **Review actions**, the button with three dots, has **Carry over requests** and **Close review** while the review is open, and **Delete review**. See [Carry over open requests](requests.md#carry-over-open-requests) and [Close a review](requests.md#close-a-review).

While the symbol index runs, a progress bar shows below the title. If the index fails, the reason shows there.

## The review tour

Your first review shows a short tour of the review screen, in two parts:

- On the **Code** tab with a folder: the file tree, the table of folders and files, **Quick open**, the **Requests** tab, and **Agent prompts**. Without an API token, the last step links to **Settings**.
- On the first file that you open in an open review: the line numbers with the **+** that comments, **Symbols**, and **Go to declaration**.

**Next** and **Back** move through the steps. **Done** on the last step, the close button, Escape, and a click that opens another page end the part. **Skip tour** ends both parts. Your account remembers the parts that you finished or skipped, so they do not show again, also in another browser.

To see the tour again, open a page of a review, click **Help** in the header, then **Take the review tour**. On the **Code** tab, the part for the current page starts at once. Or turn on **Show the review tour** in **Settings**.

## Keep going

- [Browse the code](browse-code.md): the file tree, the file view, blame, Quick open, symbols, and declarations.
- [Comment on code](comment.md): comment on lines, symbols, and files, and send comments as a batch.
- [Requests](requests.md): follow the requests, talk with the agent, verify, reopen, carry over, and close a review.
- [Pull requests](pull-requests.md): the pull requests of your agent and the Slopmower check.
- [Keyboard shortcuts](keyboard-shortcuts.md): every shortcut of the review.
