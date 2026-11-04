# Code access

Slopmower reads the code of a GitHub repository in one of two modes: **Server copy** or **Pass-through**. When you connect a GitHub repository, you choose one under **Code access**. Nothing is preselected, and **Connect** waits for your choice. You can change the mode later on the repository page. A local repository always has a Server copy.

<!-- On this page -->

## Server copy

Slopmower keeps a full copy of the repository with its history on encrypted storage, and fetches each push into it. Every read of the code comes from this copy. The copy keeps the commit of each review and each linked commit, also after a force-push.

Slopmower deletes the copy when you delete the data of the repository or your account, when you remove the repository from the GitHub App, or when Slopmower loses access to it.

## Pass-through

Slopmower keeps no copy of the code. Each time that you or your agent read code, Slopmower reads it from GitHub. The server holds the code in memory only while it answers the request or builds the symbol index, and never writes it to a disk or to the database.

Two jobs need a whole commit: the symbol index of a large commit, and the search for the code of a request that moved to another file. For them, Slopmower downloads that one commit, without its history, into memory and deletes it when the request or the job ends.

On a push, and when you click **Refresh**, Slopmower checks that it can still read the repository and reads the head of the default branch, without code. While it connects, the status shows **Connecting**. When the check fails, the status shows **Failed**, code does not load, and **Refresh** tries again.

## Compare the modes

| Feature | Server copy | Pass-through |
|---|---|---|
| File tree, file view, raw, README, diff sides | From the copy | From GitHub, per request |
| Blame | Honours `.git-blame-ignore-revs` | Without `.git-blame-ignore-revs` |
| **Changes** tab | Reads every changed file of a commit | Reads at most 3,000 changed files of a commit |
| Symbol search, go to declaration, outline, symbol anchors | Yes | Yes |
| Requests follow the code | Yes | Yes. A rename inside a comparison of more than 300 files is not followed. |

| Mode | Pros | Cons |
|---|---|---|
| Server copy | Fastest. Works during a GitHub outage and uses no GitHub rate limit for code. A force-pushed review commit stays readable. | Slopmower keeps a full copy of the repository with its history on encrypted storage until you remove the repository or Slopmower loses access. |
| Pass-through | Slopmower stores no code. Every feature works. | Each read waits for GitHub, about 0.3 to 1 s, blame up to about 3 s. A short commit SHA that matches several commits is reported as not found. Code does not load during a GitHub outage or when the rate limit of the installation is used up. A force-pushed review commit that GitHub deletes becomes unreadable. The server holds code in memory while it answers a request or builds the index. The first review of a large repository downloads one commit into memory. |

## Change the mode

1. Open the repository: click its name under **Connected repositories**.
2. In the header, next to **Code access**, click **Change** and choose the other mode.
3. Read what the change does, then click **Switch**.

The status shows **Connecting** or **Cloning** until the change ends.

- **To Pass-through:** Slopmower deletes its copy of the repository, with the review commits and linked commits that it kept, then checks its access on GitHub. The symbol index stays.
- **To Server copy:** Slopmower clones the repository again, with the review commits and linked commits that GitHub still has. Code loads when the clone is ready.

When the change ends, Slopmower runs the symbol index and finds the code of the requests again for each open review. Your reviews and requests stay.

## Privacy

| Data | Server copy | Pass-through |
|---|---|---|
| Code | A full copy with its history, on encrypted storage, until the copy is deleted as [Server copy](#server-copy) says | In memory only, while a request or a job runs |
| Symbol index | File paths, symbol names, and their lines, in the database | The same |
| Commit data | In the copy | The file paths of each commit, and the message, author name and email, and time of each commit, in a cache for at most 1 day |

In both modes:

- A new request stores the path, the line numbers, and a hash of the code, but not the code. To show the code of a request, Slopmower reads it again from the copy or from GitHub. When it cannot, for example after GitHub deleted a force-pushed commit, the request shows **Code not available**.
- A request that stored lines of code in an earlier version of Slopmower keeps them until you delete its review.
- The text that people write keeps the code that it contains: comments, replies, and the plans and diffs that your agent proposes. It stays until you delete the review.
- Your coding agent reads the code through Slopmower.

The [privacy policy](privacy.md) gives the full list of the data that Slopmower keeps.
