# Several users per tenant

Status: backlog. The proof of concept has one user per tenant, by decision D17. `User` and `Tenant` are separate tables so that this change needs no data migration of existing tenants.

## Why

- A team wants to review one codebase together and share the requests.
- A team wants one set of connected repositories, not one clone per developer.

## What

- The owner of a tenant invites other users by GitHub login. An invited user signs in with GitHub and joins the tenant.
- Every member has unrestricted access inside the tenant, by decision D17. There are no roles and no teams.
- Drafts, viewed-file marks, and API tokens stay per user. Requests, comments, commit links, and reviews are shared.
- Actors show which member acted. An agent acts for the member who owns its token.
- `awaiting: user` means any member. "Waiting for you" shows the requests that wait for a member.
- The owner can remove a member. The member's tokens are revoked. The member's events keep their actor.
- Account deletion of a member deletes the user and the user's drafts, tokens, and viewed marks. Deletion of the whole tenant stays with the owner.
- GitHub candidates depend on the member's own GitHub access, so each member sees the repositories that the member can install. Connected repositories are shared.
- Risk from architecture section 12: every member can instruct the agents of every other member through request text. The invitation page says so.

## Acceptance criteria

1. An invited user joins the tenant and sees its repositories, reviews, and requests.
2. A member's drafts and viewed marks are invisible to other members.
3. A removed member gets 401 for its tokens and cannot open the tenant's data.
4. The cross-tenant suite passes with several users per tenant.
5. Every event names the member or the member's agent as actor.

## Dependencies

- M05 tenancy, M10 sign-in, M14 API tokens, M31 data deletion, and M32 and M33 GitHub sign-in and repositories.
