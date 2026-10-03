---
name: make-webhook
description: Use when the application should receive webhooks from an external service such as Stripe or GitHub.
version: 1.0.0
updated: 2026-09-07
symfony-versions: ">=6.4"
maker-bundle-versions: ">=1.68"
---

# Webhook receiver

```bash
symfony console make:webhook stripe
```

The name is the argument (letters, digits, underscores, dots and dashes) and becomes the
key the webhook is reachable under. The maker installs `symfony/webhook` itself and adds
the entry to `config/packages/webhook.yaml`.

## What you get

Two classes, and the split is the point: a request parser extending
`AbstractRequestParser` that turns the HTTP request into a `RemoteEvent`, and a consumer
marked `#[AsRemoteEventConsumer]` that reacts to it. Parsing and verification live in
the parser; what the application does about the event lives in the consumer.

## What is yours

- **The request matcher.** The maker asks which matchers apply (method, path, host, IPs,
  JSON) only when you omit the name. If you pass the name, it asks nothing, and the
  generated `getRequestMatcher()` starts as an empty `ChainRequestMatcher`, which matches
  every request. Tighten it to what the sender actually does.
- **Verification.** Check the service's signature header in the parser and throw
  `RejectWebhookException` on mismatch. A webhook endpoint without verification accepts
  events from anyone who finds the URL.
