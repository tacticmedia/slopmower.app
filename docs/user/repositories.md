# Repositories

Slopmower reviews the repositories that you connect. It keeps its own copy of each connected repository and fetches new commits into it. **Repositories** in the header lists your connected repositories and the repositories that you can connect. While no repository is connected, it shows the steps of [Getting started](getting-started.md) in place of the lists.

<!-- On this page -->

## Give Slopmower access on GitHub

Slopmower reads your code through the Slopmower GitHub App. The App gets read-only access to the contents and the pull requests of the repositories that you choose. It cannot write to them. It can write checks, so a pull request shows which requests it covers, as [The Slopmower check](pull-requests.md#the-slopmower-check) shows.

1. Open **Repositories**.
2. Under **GitHub repositories**, click **Configure on GitHub**.
3. On GitHub, choose the account or organization, then choose **Only select repositories** and select the repositories to review.
4. Click **Install**. GitHub sends you back to Slopmower.

**GitHub repositories** lists the repositories that the App can read, as of your last sign-in. When GitHub sends you back after an installation, Slopmower lists them again. After another change on GitHub, click **Refresh**: Slopmower signs you in through GitHub again and lists them again. Each row shows the visibility, the default branch, and the account where the App is installed.

While no repository is connected, the first step of Getting started has **Install the GitHub App** in place of **Configure on GitHub**. After the installation, the step lists the repositories, with **Add repositories on GitHub**.

To add or remove repositories later, click **Configure on GitHub** again, then **Refresh**. When you remove a repository from the App, Slopmower deletes its copy of the code. Your reviews stay until you delete them.

To review the repositories on your own disk, without GitHub, see [Run it locally](local-run.md). Then **Repositories** also shows **Local directories**.

## Connect a repository

1. Open **Repositories**.
2. Click **Connect** next to a repository under **GitHub repositories** or **Local directories**.

The repository appears under **Connected repositories**, and its row in the list of candidates shows **Connected**. When you connect from the first step of Getting started, the repository page opens. Slopmower makes its copy of the repository. When the status is **Ready**, you can start a review.

Your plan limits the number of connected repositories and the size of the copies. See [Usage limits](account.md#usage-limits).

## Repository status

**Connected repositories** shows each repository with its source, its default branch, its status, and the number of open reviews. Click its name to open the repository page.

| Status | Meaning |
|---|---|
| Cloning | Slopmower makes its copy of the repository |
| Fetching | Slopmower fetches new commits |
| Ready | The copy is ready |
| Failed | The first copy failed. The error shows below the status. |
| Unavailable | Slopmower has no access to the repository any more, and it deleted its copy |

After the first fetch, the status also shows when Slopmower fetched last. When a copy or a fetch fails, the error shows below the status until the next one succeeds.

## Get new commits

For a GitHub repository, GitHub tells Slopmower about each push, and Slopmower fetches the new commits. A local repository sends no notifications. [When Slopmower sees new commits](local-run.md#when-slopmower-sees-new-commits) lists when Slopmower fetches from your disk.

Slopmower also fetches when your agent links a commit that Slopmower does not have yet, and when you start a review, unless you name a full commit SHA that its copy has already.

To fetch at once, open the repository and click **Refresh**. After a failed first copy, **Refresh** starts the copy again.

## The repository page

The header of the repository page shows the name, the source, and the default branch. For a GitHub repository, it also shows the visibility, the owner, **View on GitHub**, and the switch **Check every pull request**, which [Check every pull request](pull-requests.md#check-every-pull-request) describes.

**Reviews** lists the open reviews of the repository. Select **Show closed** to list the closed reviews too.

While the repository has no open review, the steps of Getting started show next to **Start a review**, and the page has no list of reviews. Click **Show closed reviews** to see the closed reviews.

## Start a review

1. Open the repository: click its name under **Connected repositories**.
2. Under **Start a review**, keep the default branch in **Ref**, or type another branch, tag, or commit SHA. You can also give the review a **Title**.
3. Click **Start review**.

Slopmower pins the review to the commit that the ref points to when the review starts. The file tree opens, and the progress bar shows the progress of the symbol index. To take the open requests of an older review with you, see [Carry over open requests](requests.md#carry-over-open-requests).

## Latest commits

Below **Start a review**, **Latest commits on** and the name of the default branch lists the 10 newest commits of the branch, as of the last fetch. Each row shows the first line of the commit message, the author, the time, and the short SHA with a copy button. **Start review** in a row starts a review at that commit. **Refresh** fetches new commits.

Until the copy is ready, the list says that the commits show when the copy is ready.

## Delete the data of a repository

To delete the reviews, the symbol index, and the copy of a repository, use **Delete data** in **Settings**. See [Delete repository data](account.md#delete-repository-data).
