# M03 Domain events and payload codec

Workflow, conventions, and status: [README](README.md).

## Goal

One event class per event type, with a strict codec that round-trips `review_event.payload`.

## Depends on

M02.

## In scope

- `EventType` with the 14 types: the 13 types of architecture section 3 plus `review.closed`. The list equals `EventType` in `docs/specs/schemas/common.schema.json`.
- One `final readonly` class per type with `toPayload()` and `fromPayload()`.
- Payload changes from docs/specs:
  - `review.closed` carries `{note}`;
  - `commit.found` carries `{commitSha, requestIds}`.
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
2. Add a short docblock on the codec namespace that records decision P7: explicit codecs, no Serializer, no reflection.
3. Readers default missing optional fields, because stored events are never rewritten. New fields are additive.

## Acceptance tests

All are unit tests.

- **U `EventRoundTripTest`** proves that no event loses data in storage. A data provider covers all 14 types.
  - "fromPayload(toPayload(event)) equals the event"
  - "The encoded JSON equals the fixture string"
- **U `ArchitectureExamplePayloadTest`** proves that the documented example decodes.
  - "Decodes the request.opened example of architecture section 3"
- **U `EventPayloadSchemaTest`** proves that payloads match docs/specs. Write it only when docs/specs defines schemas for stored payloads. If it does not, record that in the report and skip this test.
  - "Every payload validates against its docs/specs schema"
- **U `PayloadStrictnessTest`** proves that corrupt payloads fail loudly.
  - "A missing field, a wrong type, an unknown enum value, and an unknown event type each throw an error that names the field"
- **U `WontDoReasonTest`** proves the reason rule.
  - "Rejects a reason with fewer than 20 characters after trimming"
- **U `CommitLinkedPayloadTest`** proves the request list rule.
  - "Rejects empty or duplicate requestIds"
- **U `CommitFoundPayloadTest`** proves the payload of docs/specs.
  - "Carries the commit SHA and the linked request IDs"

## Verification commands

```sh
make test
make stan
make check
```

## Done

All 14 event types round-trip.
