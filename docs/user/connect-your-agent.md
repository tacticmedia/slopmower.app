# Connect your agent

Your coding agent connects to the Holistic Review MCP server with an API token. The Holistic Review skill teaches the agent the workflow. This page uses Claude Code as the example.

## 1. Create a token

1. Open **Settings**.
2. Under **API tokens**, click **Create token**.
3. Type a name. The name is the agent name that Holistic Review shows on every comment and change that the agent makes, for example `claude-code laptop`.
4. Choose the scope: **All repositories**, or one repository. A token for one repository cannot see your other repositories.
5. Optional: choose an expiry date.
6. Click **Create**.
7. Copy the token. It starts with `hr_`. Holistic Review shows it only once.

If you lose the token, revoke it and create a new one. **Settings** shows when each token was last used.

## 2. Put the token in an environment variable

Set `HOLISTIC_REVIEW_TOKEN` in the shell that starts your agent, or in your shell profile:

```sh
export HOLISTIC_REVIEW_TOKEN=hr_your_token
```

Do not put the token into a file that you commit.

## 3. Configure the MCP server

Add this file as `.mcp.json` in the root of your repository. Replace `<host>` with the address of your Holistic Review. For a local run, the URL is `http://localhost:8000/_mcp`.

```json
{
  "mcpServers": {
    "holistic-review": {
      "type": "http",
      "url": "https://<host>/_mcp",
      "headers": {
        "Authorization": "Bearer ${HOLISTIC_REVIEW_TOKEN}"
      }
    }
  }
}
```

The file contains no secret. Claude Code reads the token from `HOLISTIC_REVIEW_TOKEN`. When you create a token, **Settings** shows the same snippet with your address.

Other agents: add an HTTP MCP server with the same URL and the same `Authorization` header in the configuration of the agent.

## 4. Install the skill

Run this command in the root of your repository:

```sh
curl -fsSL https://<host>/docs/skill/SKILL.md --create-dirs -o .claude/skills/holistic-review/SKILL.md
```

For a local run, use `http://localhost:8000/docs/skill/SKILL.md`. You can commit the skill file, so that everybody who works on the repository has it. Run the command again to get a newer version.

Other agents that read Agent Skills from another directory need the file in that directory.

## 5. Give your agent a review

1. Open the review in Holistic Review.
2. In the review header, click **Copy prompt** next to the agent prompt. The prompt contains the review ID, for example:

   ```text
   Work on Holistic Review 01K6B3A1B2C3D4E5F6G7H8J9KM
   ```

3. Start the agent in your repository and paste the prompt. If the agent asks to approve the `holistic-review` MCP server, approve it.

The agent then lists the requests that wait for it, asks questions, and works on the requests. If you give no review ID and several reviews are open, the agent asks you which review to use.

To let the agent keep watching the review for your replies, ask it to do so. In Claude Code you can use the `/loop` command.

If you want a pull request for the work, ask the agent for one, or put the rule into the instructions of your repository, for example `CLAUDE.md` or `AGENTS.md`. The agent then links the pull request to its requests, and Holistic Review shows the link on each request. For a GitHub repository, the pull request must belong to the reviewed repository.

## If something goes wrong

| What you see | What to do |
|---|---|
| The agent reports `unauthorized` | Check that `HOLISTIC_REVIEW_TOKEN` is set in the shell that started the agent, and that the token is not revoked or expired |
| The agent reports `not_found` for your repository or review | Check the scope of the token. A token for one repository cannot see other repositories. |
| The agent reports `commits_not_available` | For a GitHub repository, push the commits. For a local repository, make sure that the commits are on a branch of the connected repository. |
| The agent reports `review_closed` | The review is closed. Start a new review. |
