# F51 Sentry data rules

Workflow, conventions, and status: [feature track](features.md). Jan added Sentry error reporting on 2026-10-15 (`1b9db4c`: `sentry/sentry-symfony` with the recipe configuration, the bundle in `prod` only, `SENTRY_DSN` empty by default). With the defaults, an error event sends request bodies and stack frame arguments to Sentry. [Architecture section 12](../architecture.md#12-risks), D66, F45, and the F43 privacy policy say that no log holds code or the text of agent calls, and the privacy policy does not name Sentry. F51 makes the error reports follow these rules and documents Sentry.

## Goal

- An error report holds no request body, no frame argument, no cookie, no IP address, and no user data.
- Sentry gets server errors only, not client errors.
- The privacy policy, the deployment docs, and the architecture name Sentry.

## Facts

Verified in the installed code on 2026-10-15, sentry/sentry 4.34.0 and sentry/sentry-symfony 5.14.0:

- `send_default_pii` is false by default, so the IP address, the cookies, the user, and the PII headers stay out.
- `max_request_body_size` is `medium` by default. `RequestIntegration` attaches the request body of up to 10,000 bytes to each error event, whatever `send_default_pii` is (`DataCollectionPolicy::MAX_REQUEST_BODY_LENGTH`).
- `FrameBuilder` adds the arguments of each frame when the backtrace has them. PHP keeps them when `zend.exception_ignore_args` is Off, as in `php.ini-development`, which the image uses until F50.
- `context_lines` is 5: the lines of our source code around each frame.
- The bundle option `options.data_collection` (`DataCollectionOptions`) replaces the legacy options. It has `user_info`, `cookies`, `http_headers`, `http_bodies`, `url_query_params`, `database_query_data`, `queues`, `stack_frame_variables`, and `frame_context_lines`.
- The bundle captures each exception of `kernel.exception` (`ErrorListener`), console errors, and failed Messenger messages (`messenger.capture_soft_fails` true). Tracing is off without `traces_sample_rate`. Logs are off (`enable_logs` false). No Monolog handler sends to Sentry.

## Decisions

- **Data collection.** `options.data_collection` in `config/packages/sentry.yaml`: no user info, no cookies, no HTTP bodies, no stack frame variables. The request URL with its query string, the method, the headers that are not PII, and the context lines of our code stay, because an operator needs them and they hold no row and no request text. If a query parameter can hold the text of a user or an agent, it is removed. The implementer lists the query parameters of the REST API and decides.
- **Server errors only.** A `before_send` callback drops the event of an exception that ends in a response below 500. The implementer checks how the API maps its errors to 4xx responses (`ErrorCode`), so that every 4xx path is dropped, for example `limit_exceeded` and `rate_limited`.
- **Integrations.** The error listener, the console listener, and the Messenger listener stay. No Monolog handler and no tracing.
- **Privacy policy.** Sentry is a recipient: the operator of Sentry processes error reports with the request URL, the method, the exception, and the stack trace, with IDs and no request body. The region, the legal entity, and the retention of Sentry are `[TO SUPPLY]` markers.
- **D67** in [architecture section 11](../architecture.md#11-decisions) records these rules.

## Depends on

Nothing. F50 (peer) moves `docs/deployment.md` to `docs/deployment/`. After F50, `SENTRY_DSN` goes into `docs/deployment/README.md`. The feature that merges second rebases.

## In scope

- `config/packages/sentry.yaml`, the `before_send` callback, and their tests.
- `.env.prod.dist` and the deployment docs: `SENTRY_DSN`, empty to switch Sentry off.
- `docs/user/privacy.md`: the recipient list, the data table, and the retention list.
- Architecture: D67, section 12 (the risk "We host other developers' source code"), and section 9 if it lists external services.

## Out of scope

- The Monolog integration of Sentry, and tracing.
- `zend.exception_ignore_args` in the production image (F50).

## Acceptance tests

- **F** `SentryOptionsTest`: the `prod` container has the data collection rules of this scope. Build it as `ProdLogChannelsTest` does.
- **U** or **F**: the `before_send` callback drops an event for a 4xx exception and for each API error that maps to 4xx, and keeps an event for a 5xx exception and for an exception without a response.

## Verification

- `make check` is green.
- With a test DSN in `prod`, an error event of a failed request has no `request.data`, no frame `vars`, and no `user`. Record the event JSON in the report, for example through a `before_send` that writes it to a file, or through the transport of the SDK in a test.
