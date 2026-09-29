# Run it locally

You can run Holistic Review on your own computer with Docker and review the git repositories on your disk. A local run needs no GitHub account and no GitHub App.

## What you need

- Docker with Docker Compose v2.
- `git` and `make`.
- A clone of the Holistic Review source code.
- A directory that holds your git repositories, for example `$HOME/dev`. Holistic Review lists the repositories directly under it.

## Start Holistic Review

1. Open a terminal in the Holistic Review clone.
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

5. Open http://localhost:8000 in your browser. The sign-in page shows **Sign in as jan**, with the name from `APP_LOCAL_SIGN_IN`. Click it. The first sign-in creates your local account.

To stop Holistic Review, run `make down`. Your reviews stay in the database volume.

## How the repository directory is used

- Docker mounts the directory read-only at `/repositories` inside the containers. Holistic Review reads it through `APP_LOCAL_REPOSITORIES_DIR=/repositories`. When `APP_LOCAL_REPOSITORIES_DIR` is empty, local repositories are off.
- Holistic Review lists only the direct child directories that are git repositories: a directory with a `.git` directory, or a bare repository. It skips names that start with `.`.
- Holistic Review never writes to your repositories. It keeps its own copy of each connected repository and fetches new commits from your directory.

## Connect a repository and start a review

1. Open **Repositories**. The list shows the git repositories in your directory.
2. Click **Connect** next to a repository.
3. When the status is ready, click **Start review**. Keep the default branch, or type another branch, tag, or commit SHA.
4. Click **Start**. The file tree opens.

Then [connect your agent](connect-your-agent.md). In `.mcp.json`, use `http://localhost:8000/_mcp` as the URL.

## When Holistic Review sees new commits

Your repository sends no notifications to Holistic Review. Holistic Review fetches from your directory when:

- you start a review;
- the agent links a commit that Holistic Review does not have yet;
- the agent marks a request ready for review;
- you click **Refresh** on the repository.

A commit is visible when it is on a branch of the connected repository. A commit in a worktree of that repository counts, because worktrees share the branches of the repository. You do not have to push.

## Local sign-in

`APP_LOCAL_SIGN_IN` works only in the development environment of Holistic Review. In every other environment it has no effect. Do not use a local run as a shared server on a network.
