# M23 Public docs and downloadable skill

Workflow, conventions, and status: [README](README.md).

## Goal

Render the public documentation of `docs/user/` at `/docs`, and serve the skill raw at a stable URL, so that one `curl` command installs it. Decision D28.

## Depends on

M16, M18 for the prompt snippet, M19 for the Settings screen.

## In scope

- `composer require twig/markdown-extra league/commonmark`, versions 3.29.0 and 2.10.3. `twig/extra-bundle` is installed already.
- The CommonMark converter escapes raw HTML with `html_input: escape`, drops unsafe links with `allow_unsafe_links: false`, and renders GFM tables. Confirm the configuration keys with `make console c="config:dump-reference twig_extra"`. Where no key exists, configure the converter service with attributes in a small factory class.
- `DocsController`, public, no sign-in:
  - `GET /docs` renders `docs/user/index.md`;
  - `GET /docs/{slug}` renders `docs/user/<slug>.md`. The slugs come from a whitelist that `DocsCatalog` builds from the listing of `docs/user/*.md`. The controller never builds a path from input. An unknown slug gives 404;
  - relative links between the pages, written as `<slug>.md`, render as `/docs/<slug>`. The same files then work on GitHub and in the app;
  - `GET /docs/skill/SKILL.md` returns `docs/user/skill/SKILL.md` raw: `Content-Type: text/markdown; charset=utf-8`, `Content-Disposition: inline; filename="SKILL.md"`, or `attachment` with `?download=1`. The ETag is the SHA-256 of the content. `Cache-Control: public, max-age=300`. `If-None-Match` gives 304;
  - `GET /docs/schemas/v1/{file}.schema.json` returns the files of `docs/specs/schemas/` with `Content-Type: application/schema+json`, as docs/specs/rest-api-requests.md section 7 says. The file names come from a whitelist built from the directory listing.
- A plain Twig layout for the pages.
- The pages in `docs/user/` exist: `index.md`, `getting-started.md`, `local-run.md`, `connect-your-agent.md`, `reviewing.md`. Check them against the implementation. Correct commands, paths, UI labels, and error codes that differ. Do not describe features that the implementation does not have yet.
- The skill in `docs/user/skill/SKILL.md` exists. Check it against the implementation and correct it where it differs:
  - `list_reviews`, and asking the user when several reviews are open and the user named none;
  - `get_updates(review, cursor)`;
  - local repositories: committing on a branch of the connected repository is enough. GitHub repositories: push;
  - `review_closed`, `commits_not_available`, and the other error codes of docs/specs/mcp.md section 5.
- Links:
  - Settings shows the install command `curl -fsSL <skillUrl> --create-dirs -o .claude/skills/holistic-review/SKILL.md`, built from `links.skillUrl` of `/api/v1/me`, and a link to `/docs/connect-your-agent`;
  - the agent prompt snippet of the review header links to `/docs/connect-your-agent`.

## Out of scope

- Hosted-docs polish and styling beyond a plain layout.
- A Claude Code plugin for the skill: [docs/backlog/skill-plugin.md](../backlog/skill-plugin.md).

## Files (representative)

- `src/Controller/DocsController.php`, `src/Docs/DocsCatalog.php`, `src/Docs/MarkdownRenderer.php`, `src/Docs/DocsLinkRewriter.php`.
- `templates/docs/layout.html.twig`, `templates/docs/page.html.twig`.
- `docs/user/*.md` and `docs/user/skill/SKILL.md`: corrections only.
- `assets/settings/SkillInstall.tsx`, `assets/settings/skillInstall.ts`, `assets/settings/skillInstall.test.ts`.
- `tests/Functional/Docs/*Test.php`, `tests/Unit/Docs/*Test.php`.

## Tasks (ordered)

1. Install the packages and configure the converter.
2. Write the catalog, the renderer, the link rewriter, and the controller.
3. Check and correct the pages and the skill.
4. Add the install command to Settings and the documentation link to the prompt snippet.
5. Write the tests.

## Acceptance tests

- **F `DocsRenderingTest`** proves the public pages.
  - "Every page in docs/user renders without sign-in"
  - "Traversal attempts such as /docs/..%2Fcomposer.json and %2e%2e give 404"
  - "Links to other pages render as /docs/<slug>"
- **F `RawSkillTest`** proves the raw skill download.
  - "The response bytes equal docs/user/skill/SKILL.md"
  - "The content type is text/markdown and the disposition is inline, or attachment with download=1"
  - "A matching If-None-Match gives 304"
- **F `SchemaFilesTest`** proves the schema download.
  - "A schema file returns its bytes as application/schema+json"
  - "An unknown file gives 404"
- **U `MarkdownSafetyTest`** proves safe rendering.
  - "Raw HTML is escaped"
  - "javascript: links are dropped"
  - "GFM tables render"
- **U `SkillFrontmatterTest`** proves the Agent Skills format.
  - "name is holistic-review, the name of the install directory"
  - "description has at most 1,024 characters"
  - "metadata has api v1"
  - "The body has fewer than 500 lines"
- **F `SkillMatchesToolsTest`** proves that the skill follows the server.
  - "The tools named in the skill equal tools/list"
  - "Every error code in the skill exists in docs/specs"
- **V `SkillInstallTest`** proves the Settings command.
  - "Builds the curl install command from skillUrl"

## Verification commands

```sh
make test
make front
curl -I localhost:8000/docs/skill/SKILL.md
curl -fsS localhost:8000/docs/connect-your-agent
make check
```

## Done

An agent can be set up from the public documentation alone.
