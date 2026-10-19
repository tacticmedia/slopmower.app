# Getting started

This page shows the four steps from your first sign-in to the first request that your agent works on. To run Slopmower on your own computer, see [Run it locally](local-run.md).

To sign in, open Slopmower in your browser, click **Sign in with GitHub**, and approve the sign-in on GitHub. The first sign-in creates your account. Only you can see your repositories, reviews, and requests.

After the sign-in, **Repositories** shows the four steps until you connect a repository. The repository page then shows them while it has no open review. Each step has a **Learn more** link to its section of this page.

## 1. Connect a repository

Slopmower reads your code through the Slopmower GitHub App. The App gets read-only access to the contents and the pull requests of the repositories that you choose. It cannot write to them. It can write checks, so a pull request shows which requests it covers, as [The Slopmower check](pull-requests.md#the-slopmower-check) shows.

1. Click **Install the GitHub App**.
2. On GitHub, choose the account or organization, then choose **Only select repositories** and select the repositories to review.
3. Click **Install**. GitHub sends you back to Slopmower, and the step lists the repositories that the App can read.
4. Click **Connect** next to a repository. The repository page opens.

To add or remove repositories later, click **Add repositories on GitHub** in the step, or **Configure on GitHub** on **Repositories**. When you remove a repository from the App, Slopmower deletes its copy of the code. Your reviews stay until you delete them.

Slopmower makes its own copy of the repository. The status on the repository page shows the progress. When the status is **Ready**, you can start a review.

Your plan limits the number of connected repositories and open reviews, and the size of the copies. **Settings** shows your plan, and the use and the limit of each item. A copy that is larger than a size limit fails, and the status names the limit.

Without a paid plan, you have the free plan. To get higher limits, buy a plan for your GitHub account or for an organization on the Slopmower page of GitHub Marketplace. **Settings** links to it. GitHub installs the App and sends you back to Slopmower, where you sign in again. You get the highest plan of your GitHub account and of the accounts where the App is installed. When you cancel the plan of your own GitHub account, GitHub keeps the App installed. If the App is no longer installed on any account that you use with Slopmower 30 days after the cancellation, and you have not bought a plan again, Slopmower deletes your account and all its data.

## 2. Pick a point to review

A review covers the whole code at one commit. On the repository page, **Start a review** shows two ways to choose the commit:

- **Ref** holds the default branch. Keep it, or type another branch, tag, or commit SHA.
- **Latest commits on** the default branch lists its newest commits as of the last fetch. **Refresh** fetches new commits.

## 3. Start a review

1. Optional: type a **Title** for the review.
2. Click **Start review** below **Ref**, or **Start review** next to one of the latest commits.

Slopmower pins the review to the commit that the ref points to when the review starts. The file tree opens. The progress bar shows the index progress.

## 4. Connect your agent

You can do this step at any time. It is done while you have an active API token.

1. Open **Settings**, and under **API tokens**, click **Create token**.
2. Give the token to your agent, as [Connect your agent](connect-your-agent.md) shows.

## Next steps

Read [Comment on code](comment.md) to learn how to comment, and [Requests](requests.md) to learn how to verify, reopen, and close a review.
