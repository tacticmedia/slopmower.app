# Run it locally

You can run Slopmower on your own computer with Docker and review the git repositories on your disk. A local run needs no GitHub account and no GitHub App.

## What you need

- Docker with Docker Compose v2.
- `git` and `make`.
- Node.js 24 with npm, to build the web interface.
- A clone of the Slopmower source code.
- A directory that holds your git repositories, for example `$HOME/dev`. Slopmower lists the repositories directly under it.

## Start Slopmower

1. Open a terminal in the Slopmower clone.
2. Set the directory that holds your repositories. Use an absolute path:

   ```sh
   export LOCAL_REPOSITORIES_HOST_DIR="$HOME/dev"
   ```

3. Set the name of your local user:

   ```sh
   export APP_LOCAL_SIGN_IN=jan
   ```

4. Start the containers:

   ```sh
   make up
   ```

5. Build the web interface:

   ```sh
   npm ci
   npm run build
   ```

   For development with hot reload, keep `npm run dev` running instead of `npm run build`.

6. Open http://localhost:8000 in your browser. The sign-in page shows **Sign in as jan**, with the name from `APP_LOCAL_SIGN_IN`. Click it. The first sign-in creates your local account.

To stop Slopmower, run `make down`. Your reviews stay in the database volume.

## How the repository directory is used

- Docker mounts the directory read-only at `/repositories` inside the containers. Slopmower reads it through `APP_LOCAL_REPOSITORIES_DIR=/repositories`. When `APP_LOCAL_REPOSITORIES_DIR` is empty, local repositories are off.
- Slopmower lists only the direct child directories that are git repositories: a directory with a `.git` directory, or a bare repository. It skips symbolic links and names that start with `.`.
- Slopmower never writes to your repositories. It keeps its own copy of each connected repository and fetches new commits from your directory.

## Connect a repository and start a review

1. Open **Repositories**. The **Local directories** table shows the git repositories in your directory.
2. Click **Connect** next to a repository. The repository appears under **Connected repositories**.
3. When its status is **Ready**, click its name.
4. Under **Start a review**, keep the default branch in **Ref**, or type another branch, tag, or commit SHA.
5. Click **Start review**. The file tree opens.

Then [connect your agent](connect-your-agent.md). In `.mcp.json`, use `http://localhost:8000/_mcp` as the URL.

## When Slopmower sees new commits

Your repository sends no notifications to Slopmower. Slopmower fetches from your directory when:

- you start a review;
- the agent links a commit that Slopmower does not have yet;
- the agent marks a request ready for review while one of its linked commits is pending;
- you click **Refresh** on the repository.

A commit is visible when it is on a branch of the connected repository. A commit in a worktree of that repository counts, because worktrees share the branches of the repository. You do not have to push.

## Local sign-in

`APP_LOCAL_SIGN_IN` works only in the development environment of Slopmower. In every other environment it has no effect. Do not use a local run as a shared server on a network.
