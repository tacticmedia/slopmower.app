# M03 Domain events and payload codec

Workflow, conventions, and status: [README](README.md).

## Goal

One event class per event type, with a strict codec that round-trips `review_event.payload`.

## Depends on

M02.

## In scope

- `EventType` with the 14 types of architecture section 3. The list equals `EventType` in `docs/specs/schemas/common.schema.json`.
- One `final readonly` class per type with `toPayload()` and `fromPayload()`. The payloads are the ones of architecture section 3, including `available`, `subject`, and `committedAt` on `commit.*`.
- A `RecordedEvent` envelope: `id`, `tenantId`, `seq`, `reviewId`, `requestId` or null, `type`, `actor`, `occurredAt`, `event`.
- `PayloadReader`: typed extraction from decoded JSON that passes PHPStan level 8.
- `ActorCodec`, `AnchorCodec`.

## Out of scope

- Persistence.
- The API `Event` representation of `docs/specs/schemas/event.schema.json`. It is a derived view that the normalizers build in M13.

## Files (representative)

- `src/Domain/Event/*.php`, `src/Domain/Event/Codec/*.php`.
- `tests/Unit/Domain/Event/*Test.php`, `tests/Fixtures/events/*.json`.

## Tasks (ordered)

1. Write the event classes and the codecs.
2. Add a short docblock on the codec namespace that records decision D37: explicit codecs, no Serializer, no reflection.
3. Stored events are never rewritten, and new fields are additive. A reader defaults a missing nullable field to null and throws for any other missing field. It ignores unknown fields, so an older release reads newer events.

## Acceptance tests

All are unit tests.

- **U `EventRoundTripTest`** proves that no event loses data in storage. A data provider covers all 14 types.
  - "fromPayload(toPayload(event)) equals the event"
  - "The encoded JSON equals the fixture string"
- **U `ArchitectureExamplePayloadTest`** proves that the documented example decodes.
  - "Decodes the request.opened example of architecture section 3"
- **U `PayloadStrictnessTest`** proves that corrupt payloads fail loudly.
  - "A missing required field, a wrong type, an unknown enum value, and an unknown event type each throw an error that names the field"
  - "A missing nullable field reads as null, and an unknown field is ignored"
- **U `WontDoReasonTest`** proves the reason rule.
  - "Rejects a reason with fewer than 20 characters after trimming"
- **U `CommitLinkedPayloadTest`** proves the `commit.linked` rules.
  - "Rejects empty or duplicate requestIds"
  - "Carries subject and commit time only when available is true"
- **U `CommitFoundPayloadTest`** proves that the log holds the git data of `CommitLink`.
  - "Carries the commit SHA, the linked request IDs, the subject, and the commit time"

## Verification commands

```sh
make test
make stan
make check
```

## Done

All 14 event types round-trip.
