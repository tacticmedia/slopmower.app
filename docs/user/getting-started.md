# Getting started

This page shows how to start your first review on a hosted Slopmower server. To run Slopmower on your own computer, see [Run it locally](local-run.md).

## 1. Sign in with GitHub

1. Open Slopmower in your browser.
2. Click **Sign in with GitHub**.
3. Approve the sign-in on GitHub.

The first sign-in creates your account. Only you can see your repositories, reviews, and requests.

## 2. Install the GitHub App

Slopmower reads your code through the Slopmower GitHub App. The App gets read-only access to the contents and the pull requests of the repositories that you choose. It cannot write to them. It can write checks, so a pull request shows which requests it covers, as [Reviewing](reviewing.md#the-slopmower-check) shows.

1. Open **Repositories**.
2. Click **Configure on GitHub**.
3. On GitHub, choose the account or organization, then choose **Only select repositories** and select the repositories to review.
4. Click **Install**. GitHub sends you back to Slopmower.

To add or remove repositories later, click **Configure on GitHub** again. When you remove a repository from the App, Slopmower deletes its copy of the code. Your reviews stay until you delete them.

## 3. Connect a repository

1. Open **Repositories**. The list shows the repositories that the App can read.
2. Click **Connect** next to a repository.

Slopmower makes its own copy of the repository. The status under **Connected repositories** shows the progress. When the status is **Ready**, you can start a review.

Your plan limits the number of connected repositories and open reviews, and the size of the copies. **Settings** shows the use and the limit of each item. A copy that is larger than a size limit fails, and the status names the limit.

## 4. Start a review

1. Open the repository: click its name under **Connected repositories**.
2. Under **Start a review**, keep the default branch in **Ref**, or type another branch, tag, or commit SHA. You can also give the review a **Title**.
3. Click **Start review**.

Slopmower pins the review to the commit that the ref points to when the review starts. The file tree opens. The progress bar shows the index progress.

## Next steps

- [Connect your agent](connect-your-agent.md) so that it can work on your requests.
- Read [Reviewing](reviewing.md) to learn how to comment, verify, and close a review.
