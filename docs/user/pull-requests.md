# Pull requests

A review covers a whole codebase at one commit, but the fixes land through the normal GitHub flow. Slopmower watches the pull requests of your agent to close that loop:

- You see which requests a pull request covers, and whether it was merged.
- Your team sees the open requests in the pull request.
- A required check stops a merge while requests are unfinished.

<!-- On this page -->

## Linked pull requests

Only your agent links a pull request to requests. When it opened a pull request for its work, it links the pull request to the requests that it covers. You never link a pull request, and Slopmower never opens one. The agent opens a pull request only when you or the instructions of your repository ask for it, as [Connect your agent](connect-your-agent.md#5-give-your-agent-a-review) shows. For a GitHub repository, the pull request must belong to the reviewed repository.

The **Development** section of the request lists its pull requests, and the conversation shows each link. Click a pull request to open it in a new tab. The agent can remove a link that it made by mistake. The conversation shows the removal too.

A link does not change the status of a request, and the request does not wait for you because of it. A merge or a close of the pull request changes no request. You still verify or reopen each request, as [Verify, reopen, or decline](requests.md#verify-reopen-or-decline) shows.

## Pull request states

For a GitHub repository, Slopmower reads each linked pull request once. Then GitHub sends each change of the pull request to Slopmower. Each pull request shows its state on GitHub: Open, Draft, Merged, or Closed. The **Development** section also shows its title. When the pull request changes on GitHub, for example when it is merged, the label changes without a reload.

A pull request shows no state when the repository is local, when Slopmower has not read it yet, when GitHub does not find it, or when the GitHub App installation has not accepted the permission Pull requests: read. When the installation accepts it, Slopmower reads each linked pull request that shows no state.

## The Slopmower check

For a GitHub repository, Slopmower sets your check **Slopmower (your login)**, with your GitHub login, on the newest commit of each open pull request that one of your requests links. Each Slopmower user has a separate check, so the requests of another user never change yours.

- The check stays in progress while a linked request is unfinished.
- It passes when each linked request is verified, declined by you, or in a closed review.

Everyone who can see the pull request can read the check: the headlines of the linked requests, their states, and the links to them in Slopmower. The headline is the title of a request, or the first line of its description while it has no title, cut to 80 characters. The check never shows the rest of a request.

Each unfinished request on a line or file of the code shows as an annotation with its headline, its state, and its link: a warning while the agent works on it, and a notice while it waits for you. **Files changed** shows the annotations on the files that the pull request changes, and the page of the check shows all of them. The summary of the check lists the linked requests per review, with a link to each. A general request has no annotation. A pull request from a fork gets no annotations, because Slopmower fetches only the branches of the repository. **Re-run** on GitHub sets the check again.

The check needs the permission Checks: write. When your installation of the GitHub App does not have it yet, accept the new permission on GitHub. Slopmower then sets your check on each open pull request that one of your requests links.

## Check every pull request

By default, only the pull requests that your requests link get your check. To check every pull request of a GitHub repository, open the repository in Slopmower and switch on **Check every pull request**. A pull request without your linked requests then gets a neutral check when it is opened, reopened, or pushed to, so your check does not block its merge.

## Block a merge until the requests are finished

The check blocks a merge only when you make it required in branch protection. First switch on **Check every pull request**, because a required check that never runs blocks every pull request. Then make your check required with a branch protection rule. GitHub lists the check after Slopmower has set it on a pull request of the repository.

1. On GitHub, open **Settings** of the repository, then **Branches**, and click **Add classic branch protection rule**.
2. Enter the default branch as **Branch name pattern**.
3. Select **Require status checks to pass before merging**, search for **Slopmower (your login)**, and select it.
4. Set the source of the check to the Slopmower app, so that no other app or person can set it.
5. Click **Create**.

A ruleset can require the check too, but it accepts an app as the source only when the app has the permission Statuses: write, which Slopmower does not request. In a ruleset, choose **any source** for the check.

When you rename your GitHub login, your check gets the new name after your next sign-in. Replace the required check in the rule with the new name. Until then, a Slopmower user who takes your old login has a check with the same name as yours.

## What Slopmower needs on GitHub

The GitHub App needs these permissions for pull requests:

| Permission | Event | Used for |
|---|---|---|
| Pull requests: read | `pull_request` | The states and the titles of the linked pull requests |
| Checks: write | `check_run` | The Slopmower check and **Re-run** |

Slopmower only reads pull requests. On GitHub it writes only the Slopmower check.
