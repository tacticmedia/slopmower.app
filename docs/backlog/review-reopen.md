# Reopen a closed review

Status: backlog. In the proof of concept a closed review stays closed, by decision D27.

## Why

- A user can close a review by mistake.
- A user can want to continue a review after a pause, without a new review and without new requests.

## What

- `POST /api/v1/reviews/{reviewId}/reopen`, session only, with an optional note. It writes `review.reopened` with the payload `{note}` and clears `closedAt`, `closedBy`, and `closeNote`. The history stays in the event log.
- A reopen of an open review answers 422 `invalid_input`.
- The read-only banner of a closed review gets a **Reopen review** button with a confirmation `Dialog`.
- Writes work again, `allowedActions` returns, and the review shows again in the default lists.
- Drafts that the close deleted are not restored. Claims stayed during the close, so the agents can continue.
- One `events` live update, and `get_updates` returns `review.reopened` in `reviewEvents`.
- All changes are additive within v1: a new endpoint and a new value in the open `EventType` set. The skill tells the agent that a reopened review continues normally.

## Acceptance criteria

1. After a reopen, every write that `review_closed` refused before succeeds.
2. A reopen of an open review answers 422 `invalid_input`. A bearer token gets 403 `forbidden`.
3. `review.reopened` appears in the `reviewEvents` of `get_updates` and validates against the event schema.
4. A UI test closes and reopens a review and comments afterwards.
5. The cross-tenant suite covers the new endpoint.

## Dependencies

- M12 Reviews API and M18 reviews screens: the close command and the review header.
- M24 live updates.
