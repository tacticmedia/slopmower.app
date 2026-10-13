# MCP OAuth

Status: backlog. The proof of concept authenticates agents with per-user API tokens, by decision D18.

## Why

- The user must create a token, copy it, and put it into an environment variable. MCP clients can do an OAuth flow for a remote server, so the user only approves access in the browser.
- OAuth tokens expire and refresh without user work. A leaked API token stays valid until the user revokes it or it expires.

## What

- Follow the MCP authorization specification of the protocol revisions that the server supports. Check the current revision before the work starts.
- The MCP server is an OAuth resource server:
  - it publishes Protected Resource Metadata as RFC 9728 defines it, at `/.well-known/oauth-protected-resource`, which answers 404 in the proof of concept;
  - a 401 carries `WWW-Authenticate: Bearer realm="slopmower", resource_metadata="<url>"`;
  - it accepts only access tokens issued for its own resource URL.
- An authorization server is necessary. `mcp/sdk` and `symfony/mcp-bundle` stop at the resource-server role. Evaluate `league/oauth2-server-bundle`: current version, license, Symfony 8 support, PKCE, and the client registration method that the MCP specification requires.
- The authorization code flow with PKCE. The consent page shows the client name and asks for the agent name and the scope: all repositories, or one repository. The same scope model as API tokens applies.
- An OAuth access token maps to the same actor as an API token: `agent`, the user who approved, and the agent name from the consent page.
- API tokens stay for REST clients and for agents without OAuth support.
- Settings lists the OAuth grants and can revoke them.

## Acceptance criteria

1. Claude Code connects to `/_mcp` without `SLOPMOWER_TOKEN`, runs the OAuth flow in the browser, and calls the tools.
2. A wire test checks the 401 header, the resource metadata document, and the refusal of a token issued for another resource.
3. A scoped grant sees only its repository and answers `not_found` for others.
4. A revoked grant gets 401 at the next call.
5. Events made with an OAuth token show the agent name from the consent page.
6. The existing API token tests pass unchanged.

## Dependencies

- M14 API tokens and M15 MCP server: the actor model and the firewall.
- M32 GitHub sign-in: the consent page needs a signed-in user.
