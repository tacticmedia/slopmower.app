# M32 GitHub sign-in

Workflow, conventions, and status: [README](README.md).

## Goal

Hosted runs sign in with GitHub, decision D23. Local sign-in stays dev-only, decision D25.

## Depends on

M10.

## In scope

- `composer config extra.symfony.allow-contrib true`, then `composer require hwi/oauth-bundle`, version 2.5.0. The recipe is a contrib recipe. Version 2.5.0 requires `symfony/form`, which the project keeps.
- The resource owner `github` with `options.csrf: true`, because the option defaults to `false`. Client ID and secret come from `GITHUB_APP_CLIENT_ID` and `GITHUB_APP_CLIENT_SECRET`. HWIOAuthBundle sends no PKCE. The architecture accepts that risk: a confidential client with a secret, the `state` check, and no stored user token.
- The `oauth` authenticator in the `main` firewall. Routes, as docs/specs/rest-api-requests.md section 7 says:
  - `GET /connect/github`: the redirect to GitHub;
  - `GET /login/check-github`: the callback, which is the GitHub App's callback URL.
- `GitHubUserProvider`, an `OAuthAwareUserProviderInterface`:
  - a tenantless lookup by `githubUserId` through `TenantFilter::suspend()`;
  - the first sign-in creates the tenant and the user with `signInMethod = github`;
  - later sign-ins update `login`, `name`, `avatarUrl`, and `lastSignInAt`.
- A "Sign in with GitHub" button on `/login` when `GITHUB_APP_CLIENT_ID` is set.
- GitHub HTTP in tests goes through `framework.http_client.mock_response_factory` with recorded responses in `tests/Fixtures/github/`.
- Check that `docs/user/getting-started.md` describes the GitHub App registration of architecture section 9 and the environment variables. Correct it where it differs.

## Out of scope

- GitHub repositories: M33.
- Webhooks: M34.

## Files (representative)

- `config/packages/hwi_oauth.yaml`, the recipe routes, `config/packages/security.yaml`.
- `src/Security/GitHubUserProvider.php`, `templates/security/login.html.twig`.
- `tests/Fixtures/github/*.json`, `tests/Functional/Security/GitHub*Test.php`.

## Tasks (ordered)

1. Allow contrib recipes and install the bundle. Read what the recipe added.
2. Configure the resource owner with `csrf: true`, the firewall, and the routes.
3. Write the user provider.
4. Add the button to the login page.
5. Record the GitHub responses for the tests and write the tests.

## Acceptance tests

All are functional tests.

- **F `GitHubUserProviderTest`** proves provisioning.
  - "The first GitHub sign-in creates one tenant and one user"
  - "A later sign-in finds the same user and updates the profile"
- **F `OAuthStateTest`** proves the `state` check.
  - "A callback with a missing or wrong state is rejected"
- **F `LoginRoutesTest`** proves the routes and the button.
  - "/connect/github redirects to GitHub with the client ID and a state"
  - "/login shows the GitHub button only when the App is configured"
- **F `LocalSignInStillDevOnlyTest`** proves that GitHub sign-in does not open local sign-in.
  - "With GitHub configured and APP_LOCAL_SIGN_IN set, local sign-in still gives 404 outside dev"

## Verification commands

```sh
make check
```

Optional by hand: register a GitHub App with the settings of architecture section 9 and sign in.

## Done

A GitHub user signs in and gets their own tenant.
