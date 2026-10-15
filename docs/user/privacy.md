# Privacy policy

Last change: [TO SUPPLY: effective date]

This policy tells you which personal data the hosted Slopmower service keeps, why, and for how long. It also tells you your rights. "We" means [TO SUPPLY: legal entity name], the operator of Slopmower.

When you [run Slopmower on your own computer](local-run.md), your data stays on your computer. We get none of it.

## Who is responsible

The controller of your personal data is:

- [TO SUPPLY: legal entity name]
- [TO SUPPLY: legal entity address]
- Email: [TO SUPPLY: privacy email]

Send every privacy question and request to this email address.

## The data that we keep

| Data | Source | Why we keep it | Legal basis |
|---|---|---|---|
| Your GitHub profile: user ID, login, name, and avatar URL. The times of your first and your last sign-in. | GitHub, at each sign-in | To make your account, sign you in, and show you as the author of your comments | Contract |
| The GitHub App installations of your account: the GitHub account, its type, and the permissions. The repositories that the App can read. | GitHub | To show the repositories that you can connect | Contract |
| A copy of each repository that you connect, with its history, and an index of its files and symbols | GitHub | To show the code in your reviews | Contract |
| Your reviews, requests, comments, replies, drafts, linked commits, and viewed files, and the history of each change | You and your coding agent | To give you the reviews | Contract |
| The pull requests of your requests: number, title, state, head commit, and head repository | GitHub | To show the pull request of a request | Contract |
| API tokens: the name, the first characters, a hash, the repository scope, and the times of creation, expiry, last use, and revocation | You | To let your coding agent use Slopmower | Contract |
| Your plan, for each GitHub account: the Marketplace plan, the billing cycle, the end of the trial, and a pending plan change | GitHub Marketplace | To apply the limits of your plan | Contract |
| Usage: the number of repositories, open reviews, and requests, and the size of each copy | Slopmower | To apply the limits of your plan | Contract |
| Rate limit counters for each token, user, repository, and account | Slopmower | To protect the service from overload | Legitimate interests |
| Session data: your sign-in state and a copy of your profile | Slopmower | To keep you signed in | Contract |
| GitHub events: the webhook messages of pushes, installations, repositories, pull requests, check runs, App authorizations, and Marketplace purchases. They contain the names and email addresses of the senders, the pushers, and the commit authors. | GitHub | To keep the copies, the installations, the pull requests, and the plans up to date | Contract |
| Server logs: times, request URLs, status codes, error messages, IDs, and IP addresses. Never your code or the text of agent calls. | Slopmower and the hosting provider | To find errors and keep the service secure | Legitimate interests |
| Error reports: the time, the request method, URL, and headers, the error message, and where in the Slopmower code the error happened. Never a request body, your code, the text of agent calls, cookies, tokens, or your IP address. | Slopmower | To find and fix errors | Legitimate interests |

The legal bases are those of the GDPR and the UK GDPR:

- **Contract** (Article 6(1)(b)): we need the data to give you the service that you asked for.
- **Legitimate interests** (Article 6(1)(f)): we need the data to keep the service secure and working. This interest does not override your rights, because we use only technical data, and only for these purposes.

We do not ask for your consent for any use, and we do not use your data for any other purpose.

We get no payment card or bank data, because GitHub handles all payments.

### Data of other people in your repositories

Your repositories can contain personal data of other people, for example the names and email addresses of commit authors. We process that data only to show your code to you and to your coding agent, on your instructions. For that data, you are the controller and we are your processor.

## Cookies and browser storage

Slopmower sets only the cookies that the service needs. It has no analytics, no advertising, and no third-party cookies. So it asks for no consent, and the cookie notice only informs you.

| Name | Type | Purpose | Lifetime |
|---|---|---|---|
| `PHPSESSID` | Cookie | Keeps you signed in | Until you sign out or close the browser |
| `csrf-token_<random>`, with the prefix `__Host-` over HTTPS | Cookie | Proves that a form or a change comes from Slopmower and not from another site | One request. The server deletes it in its answer. |
| `mercureAuthorization` | Cookie | Lets your browser get live updates of your reviews | 1 hour. Slopmower renews it while a page is open. |
| `slopmower.cookieNoticeDismissed` | Local storage | Remembers that you closed the cookie notice | Until you clear the site data in your browser |
| `slopmower.treeCollapsed` | Local storage | Remembers that you collapsed the file tree | Until you clear the site data in your browser |
| `slopmower.wrap` | Local storage | Remembers that you wrap long lines | Until you clear the site data in your browser |
| `slopmower.tree-width` | Local storage | Remembers the width of the file tree | Until you clear the site data in your browser |

Local storage stays in your browser. Slopmower does not send it to the server.

GitHub sets its own cookies when you sign in on github.com, and your browser loads avatars from GitHub. The [GitHub General Privacy Statement](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement) applies to that data.

## Who gets data

We do not sell your personal data. We do not share it for advertising.

- **GitHub** (GitHub, Inc., USA). You sign in with GitHub. Slopmower reads your repositories through the GitHub App. The GitHub Marketplace bills the paid plans. Slopmower writes check runs on pull requests. The name of a check run contains your GitHub login, for example "Slopmower (octocat)". A check run contains the titles of your reviews, and for each request its state, its headline, and a link to Slopmower. The headline is the first line of the request text, up to 80 characters. A check run does not contain the rest of the request text or the replies. Its annotations show the headline, the state, and the link of a request at a file path and a line. Slopmower writes check runs on the pull requests that you link to requests. When you switch on "Check every pull request" for a repository, it also writes a "No linked requests" check run on each other pull request of that repository that is opened, reopened, or pushed to. Each person who can see the pull request can read its check runs, so on a public repository they are public.
- **The hosting provider**, [TO SUPPLY: hosting provider]. It runs the servers, the database, the logs, and the backups [TO CONFIRM: backups].
- **Sentry**, [TO SUPPLY: Sentry legal entity]. It gets an error report when a request, a background job, or a command fails on the server. A report contains the time, the request method, URL, and headers, the error message, and where in the Slopmower code the error happened. A URL contains IDs and can contain the name of a repository and the path of a file in it. Before Slopmower sends a report, it removes the search texts, names, and confirmations from the URL, and the cookies, tokens, and IP addresses from the headers. A report never contains a request body, your code, the text of agent calls, or your user ID. Slopmower sends no report when it refuses a request because of the request itself, for example for a missing token or an invalid input.
- **Your coding agent.** When you give a token to an agent, the agent reads your reviews and your code through Slopmower. You choose the agent. Its provider processes that data under its own terms.
- **Authorities**, when the law requires it.

## Where we keep data

We keep your data in [TO SUPPLY: storage country]. GitHub processes data in the USA. Sentry processes error reports in [TO SUPPLY: Sentry region]. When we send data out of the European Economic Area, the UK, or Australia, we protect it with an adequacy decision or the standard contractual clauses [TO CONFIRM: transfer mechanism]. We make sure that each recipient protects the data as this policy says [TO CONFIRM: recipient protection].

## How long we keep data

- **Copy of a repository and its index:** we delete them at once when Slopmower loses access to the repository, for example when you remove the repository from the GitHub App or uninstall the App. Your reviews stay until you delete them.
- **Reviews and requests:** until you delete the review, the data of the repository, or your account.
- **Your account:** until you delete it with **Delete account** in **Settings**. We then revoke your API tokens, sign you out, and delete all the data of the account.
- **After you cancel the paid plan of your personal GitHub account:** your GitHub account moves to the free plan. 30 days after the cancellation, we ask GitHub again. We delete all the data of your Slopmower account if GitHub reports no Marketplace plan for your GitHub account, and your Slopmower account has no GitHub App installation left, on any GitHub account. A new purchase or installation in those 30 days stops the deletion.
- **Plan:** until GitHub tells us that the plan ended. When you delete your account, we also delete the plan of your personal GitHub account, unless another Slopmower account uses a GitHub App installation on your personal GitHub account. We keep the plan of an organization until GitHub tells us that the plan ended, because the plan belongs to the organization. A new sign-in reads the plan from GitHub again.
- **Check runs:** GitHub has no API to delete a check run. So check runs stay on GitHub after you delete a review, the data of a repository, or your account.
- **GitHub events:** we delete an event at once when we have processed it. An event or a background job that failed stays for at most 31 days.
- **Webhook delivery IDs:** 7 days.
- **Rate limit counters:** we delete them at most one day after they expire.
- **Session data:** until you sign out, or until the server removes the inactive session.
- **Server logs:** [TO SUPPLY: log retention period].
- **Error reports:** Sentry keeps them for [TO SUPPLY: Sentry retention period].
- **Backups:** [TO SUPPLY: backup retention period]. Deleted data stays in a backup until the backup expires.

## Security

- The GitHub App can read the contents of your repositories, but cannot change them. It can only write check runs.
- We keep the data of each account apart from the data of all other accounts: in the database, in the files, and in live updates.
- We keep the copies of your repositories on encrypted storage [TO CONFIRM: encrypted storage].
- Your browser and your coding agent connect to Slopmower over HTTPS [TO CONFIRM: HTTPS].
- We keep only a hash of each API token, and no GitHub sign-in token.
- Logs and error reports never contain your code or the text of agent calls.

## Your rights

You can always:

- see your data in Slopmower;
- correct your profile on GitHub. Slopmower reads it again at your next sign-in;
- delete a review, the data of a repository, or your account in Slopmower;
- ask us at [TO SUPPLY: privacy email] for a copy of your data, or for any other right below.

We answer within one month [TO CONFIRM: response time for requests]. To protect your data, we can ask you to prove that the account is yours, for example with a sign-in.

### European Economic Area and UK

Under the GDPR and the UK GDPR, you have the right to access, correct, and delete your data, to restrict its use, to get it in a machine-readable format, and to object to a use that is based on legitimate interests. You can complain to the data protection authority of the country where you live or work. The [European Data Protection Board](https://www.edpb.europa.eu/about-edpb/about-edpb/members_en) lists the authorities in the EEA. In the UK, it is the [Information Commissioner's Office](https://ico.org.uk/make-a-complaint/).

### California

Under the CCPA, as amended by the CPRA:

- **Categories of personal information** that we collected in the last 12 months: identifiers (GitHub user ID, login, name, and IP address), commercial information (plan and billing cycle), internet activity (usage, sessions, server logs, and error reports), and the content of your reviews and repositories.
- **Sources:** you, your coding agent, and GitHub.
- **Purposes:** to give you the service, keep it secure, and fix errors. We give data to GitHub, the hosting provider, and Sentry only for these purposes.
- **No sale and no sharing:** we do not sell or share your personal information, and we did not do so in the last 12 months. We do not use sensitive personal information to infer characteristics about you.
- **Your rights:** to know, delete, and correct your personal information, and no discrimination when you use these rights. An authorized agent can make a request for you.

### Australia

We handle personal information under the Australian Privacy Principles of the Privacy Act 1988. You can ask for access to your personal information and correct it. Slopmower needs a GitHub sign-in, so you cannot use it anonymously.

To make a complaint, write to us first at [TO SUPPLY: privacy email]. We answer within 30 days [TO CONFIRM: response time for complaints]. If you are not satisfied with our answer, you can complain to the [Office of the Australian Information Commissioner (OAIC)](https://www.oaic.gov.au/privacy/privacy-complaints).

## Children

Slopmower is not for children. You must be at least 16 years old to use it. We do not knowingly keep data of children. If you think that a child gave us personal data, tell us at [TO SUPPLY: privacy email], and we delete it.

## Changes to this policy

We publish each change on this page and change the date at the top. How we tell you about a material change: [TO CONFIRM: notice of material changes].

## Contact

- Privacy: [TO SUPPLY: privacy email]
- Help with Slopmower: [Support](support.md)
- [Terms of service](terms.md)
