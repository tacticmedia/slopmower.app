---
name: make-controller
description: Use when creating a controller or an HTTP endpoint in a Symfony project.
version: 1.1.0
updated: 2026-08-29
symfony-versions: ">=6.4"
---

# Controller

```bash
symfony console make:controller ProductController
symfony console make:controller ProductController --no-template   # API, no Twig
symfony console make:controller HealthController --invokable      # single action
```

The maker takes the class name as an argument. An interactive run then asks whether to
generate PHPUnit tests. Pass `--with-tests` or `--no-interaction` to skip the question.
Install it first if it is missing: `symfony composer require --dev symfony/maker-bundle`.

## What to keep

Extend `AbstractController` — always. It provides `render()`, `redirectToRoute()`,
`isGranted()` and the form helpers, and a controller is thin enough that the coupling costs
nothing.

Route metadata goes in attributes, never YAML. The maker writes one method-level
`#[Route('/product', name: 'app_product')]`. Add `#[Route]` on the class as a prefix, and
give each action a snake_case route name prefixed by the entity (`product_index`,
`product_show`).

## What the framework already does for you

- `#[MapRequestPayload]` and `#[MapQueryString]` on an action argument wire up the
  Serializer and the Validator. Hand-written `json_decode()` plus manual checks is the thing
  to avoid. Both need `symfony/serializer` and `symfony/validator` — require them rather
  than falling back to parsing by hand.
- A failed validation answers 422 for `#[MapRequestPayload]` and 404 for
  `#[MapQueryString]`, with the violations. An exception listener written to produce that
  response duplicates what the attribute does.
  `validationFailedStatusCode` changes the code when 422 is wrong for the endpoint.
- A route parameter type-hinted as an entity is resolved and 404s on its own. Query the
  repository yourself only when the lookup is genuinely more complex.
- `#[IsGranted]` and `#[Cache]` express authorisation and caching declaratively.

Business logic belongs in a service. A controller that grew past orchestration has taken on
someone else's job.
