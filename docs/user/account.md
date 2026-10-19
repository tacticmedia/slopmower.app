# Settings

**Settings** holds the API tokens of your agents, the skill install command, your plan and its limits, the review tour, and the deletion of your data. Open it from the header of Slopmower.

<!-- On this page -->

## API tokens

Your agent connects to the MCP server with an API token. The name of the token is the agent name that Slopmower shows on every comment and change that the agent makes. To create a token and connect your agent, follow [Connect your agent](connect-your-agent.md#1-create-a-token).

The table shows each token with its scope, the time when it was created and last used, its expiry, and its state:

| State | Meaning |
|---|---|
| Active | Agents can use the token |
| Expired | The expiry date has passed |
| Revoked | You revoked the token |

**Revoke** ends an active token. Agents that use it lose access at once. The comments and changes that the token made keep its name. If you lose a token, revoke it and create a new one.

## Agent skill

The skill teaches your agent the Slopmower workflow. **Agent skill** shows the command that installs it. Click **Copy command**, then run the command in the root of your repository. [Install the skill](connect-your-agent.md#4-install-the-skill) shows the details.

## Plan

**Plan** shows your plan, the end of a free trial, and a change of plan with its date. You get the highest plan of your GitHub account and of the accounts where the Slopmower GitHub App is installed. Without a paid plan, you have the free plan.

To get higher limits, buy a plan for your GitHub account or for an organization on the Slopmower page of GitHub Marketplace. **Change the plan on GitHub Marketplace** links to it. GitHub installs the App and sends you back to Slopmower, where you sign in again. GitHub Marketplace bills the plans. [Support](support.md#plans-and-billing) tells you where to ask about billing.

When you cancel the plan of your own GitHub account, GitHub keeps the App installed. If the App is no longer installed on any account that you use with Slopmower 30 days after the cancellation, and you have not bought a plan again, Slopmower deletes your account and all its data.

## Usage limits

**Usage** shows the use of your account and the limit of your plan for each item:

| Item | What counts |
|---|---|
| Connected repositories | The repositories that you connected |
| Open reviews | The reviews that are not closed |
| Requests per review | The requests of your largest open review |
| Size of one repository | The copy of your largest repository |
| Size of all repositories | The copies of all your repositories |

When a limit is reached, Slopmower refuses the next item and says that a limit of your plan is reached (`limit_exceeded`). A copy of a repository that is larger than a size limit fails, and its status names the limit. To make room, close a review, or delete the data of a repository that you no longer review.

## Review tour

The review tour shows the main actions of a review, one short part at a time. [The review tour](reviewing.md#the-review-tour) describes its parts. Your account remembers the parts that you finished or skipped.

- Turn on **Show the review tour** to see the tour again on your next review.
- Turn it off to hide the tour.

On a page of a review, **Take the review tour** in the **Help** menu of the header shows the tour again. On the **Code** tab, the part for the current page starts at once.

## Delete repository data

**Repository data** lists your connected repositories. **Delete data** deletes the reviews of a repository with their requests, comments, and pending comments, together with the symbol index, the tokens for this repository, and the copy of the repository. To confirm, type the name of the repository. You cannot undo this. You can connect the repository again.

## Delete your account

**Delete account** deletes your account with all repositories, reviews, API tokens, and copies of repositories. To confirm, type your login. You are signed out at once. You cannot undo this. The GitHub App stays installed until you uninstall it on GitHub.

## Legal and support

**Legal and support** links the [privacy policy](privacy.md), the [terms of service](terms.md), and the [support page](support.md).
