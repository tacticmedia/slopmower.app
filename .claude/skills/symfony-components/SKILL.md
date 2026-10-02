---
name: symfony-components
description: Catalog of every Symfony 8.1 component and bridge, with the purpose of each and situations where it helps. Use it before you write infrastructure code, a helper, or a service class, or before you add a third-party library, to find the Symfony component that already does the job.
---

# Symfony components

A Symfony component usually does the job already. Check this catalog before you write infrastructure code, a helper, or a service class, and before you add a third-party library.

## How to use the catalog

1. Find the area of your problem and read the scenarios. A scenario does not have to match exactly. "Maybe" is enough to look closer.
2. Read the documentation of the component before you use its API: context7 first, then the linked page, then the installed source under `vendor/`.
3. Check if the package is installed with `composer show <package>`. If it is not, install it with `composer require <package>` and let the Flex recipe write the configuration.
4. Keep it simple. Use the component directly in the code that needs it. Do not wrap it in a service class that has only one consumer.
5. If a component fits and you do not use it, give the reason in your report.

The catalog covers every component of Symfony 8.1, the Contracts, and the five bridges. The [components index](https://symfony.com/doc/current/components/index.html) lists only some of them, because the main guides document the others.

## HTTP and web

### HttpFoundation - `symfony/http-foundation`
Replaces PHP request and response globals and functions with objects for requests, responses, cookies, sessions and HTTP headers. [Documentation](https://symfony.com/doc/8.1/components/http_foundation.html)
- Stream large JSON results or server-sent events to the client without loading all data in memory
- Serve file downloads with correct file names, range requests and web server offload
- Anonymize IP addresses before you log them, or check if an IP is in a subnet or a private network
- Match a request against rules such as IP, method, host or path without the router
- Set HTTP cache headers, and send 304 Not Modified when the client copy is still valid
### HttpKernel - `symfony/http-kernel`
Turns an HTTP request into a response through a cycle of events, so code can hook into each step of request handling. [Documentation](https://symfony.com/doc/8.1/components/http_kernel.html)
- Run logic before or after every controller, such as setting the locale or adding response headers
- Convert a known exception type into a specific HTTP error response
- Resolve custom controller arguments from the request, such as a value object built from a header
- Act on a custom controller attribute, such as a rate limit marker, with a listener for that attribute
- Do slow work after the response is sent, when the server can flush the response early
### Routing - `symfony/routing`
Maps incoming request URLs to controllers and parameters, and generates URLs from route names and parameters. [Documentation](https://symfony.com/doc/8.1/routing.html)
- Map URLs with variable, validated parts to controller actions
- Generate links from route names so that a URL change needs only one edit
- Generate absolute URLs in console commands and background workers that have no HTTP request
- Serve different routes per host name or subdomain, for example in a multi-tenant application
- Keep a renamed route name working through an alias, and deprecate the old name
### Runtime - `symfony/runtime`
Decouples application bootstrapping from global state, so one front controller runs on a classic web server, a worker server or a custom runtime. [Documentation](https://symfony.com/doc/8.1/components/runtime.html)
- Run the application in a long-running worker server without changes to the front controller
- Run the application on a serverless or event-loop platform through a custom runtime
- Write a small single-command CLI application with little bootstrap code
- Change how the environment name, .env files and debug mode load at startup through runtime options
- Return a static response, such as a maintenance page, directly from the front controller
### ErrorHandler - `symfony/error-handler`
Turns PHP errors into exceptions, shows detailed debug pages in development, and renders safe error output as HTML, CLI text or serialized data. [Documentation](https://symfony.com/doc/8.1/controller/error_pages.html)
- Turn PHP warnings from file or JSON functions into exceptions, even when code uses the @ operator
- Show detailed debug pages in development and a minimal error page without internal data in production
- Return errors from an API as JSON or XML instead of an HTML error page
- Dump error pages to static HTML so the web server shows them when a request fails before the application
### WebLink - `symfony/web-link`
Manages Link HTTP headers that tell browsers to preload, prefetch or connect to resources early, also as 103 Early Hints responses. [Documentation](https://symfony.com/doc/8.1/web_link.html)
- Preload fonts, stylesheets or scripts that the browser would otherwise find late in the page
- Send 103 Early Hints so the browser downloads assets while the server still builds the page
- Tell the browser to resolve DNS or open a connection to a third-party origin before it needs it
- Prefetch resources that the next page navigation will probably need
- Parse Link headers from an external API, for example to follow its pagination URLs
### Asset - `symfony/asset`
Generates URLs for web assets such as CSS, JavaScript and image files, with versioning, shared base paths and CDN hosts. [Documentation](https://symfony.com/doc/8.1/components/asset.html)
- Add a version to asset URLs so that browsers fetch the new file after a deployment
- Serve images and other assets from one or more CDN hosts with absolute URLs
- Read hashed file names from a JSON manifest that a front-end build tool writes
- When the site runs in a subdirectory, prefix asset paths with the request base path
- Move assets to a new location by changing one base path, not every template
### AssetMapper - `symfony/asset-mapper`
Serves CSS, JavaScript and other assets with versioned file names and an importmap, so modern JavaScript works without a build system. [Documentation](https://symfony.com/doc/8.1/frontend/asset_mapper.html)
- Add third-party JavaScript packages to pages without a bundler or a build step
- Give each asset a content-hash file name so that browsers can cache it for a long time
- Load extra JavaScript and CSS only on the pages that need them
- Audit front-end dependencies for known security vulnerabilities as a CI step
- Pre-compress assets at deploy time, or compress other files such as user uploads
### PSR-7 Bridge - `symfony/psr-http-message-bridge`
Converts Symfony HTTP request and response objects to and from PSR-7 HTTP message objects. [Documentation](https://symfony.com/doc/8.1/components/psr7.html)
- Pass a Symfony request to a library or middleware that accepts only PSR-7 messages
- Inject a PSR-7 server request into a controller action instead of the Symfony request
- Return a PSR-7 response from a controller and let Symfony send it
- Convert a PSR-7 response from an external library into a Symfony response

## Security

### Security - `symfony/security-bundle`
Authenticates users and controls access to URLs, actions and objects, with firewalls, user providers, roles, voters and CSRF protection. [Documentation](https://symfony.com/doc/8.1/security.html)
- Add login with a form, a JSON endpoint, HTTP Basic, access tokens or passwordless login links
- Restrict URL patterns or controller actions to users with specific roles
- Let only the owner of a record edit it, with custom permission logic in a voter
- Check the permissions of a given user outside an HTTP request, for example in a queue worker
- Protect forms and login forms against cross-site request forgery with tokens
### PasswordHasher - `symfony/password-hasher`
Hashes and verifies passwords and other secrets with a secure algorithm, and rehashes old hashes when a better algorithm is available. [Documentation](https://symfony.com/doc/8.1/security/passwords.html)
- Hash a user password before you store it, and verify it when the user logs in
- Hash and verify secrets that are not user passwords, such as recovery codes or API tokens
- Upgrade old password hashes to a stronger algorithm when users log in
- Import users from a legacy system and keep their old hashes valid until they log in again
- Use a stronger hashing configuration for some users, such as administrators
### RateLimiter - `symfony/rate-limiter`
Limits how often an event can occur, with fixed window, sliding window or token bucket policies and shared storage. [Documentation](https://symfony.com/doc/8.1/rate_limiter.html)
- Limit the number of API requests per client, IP address or access level
- Throttle failed login attempts or password reset requests
- Limit outbound calls to a third-party API so that you stay inside its quota
- Wait until a token is free before a background job continues, instead of failing the job
- Expose the remaining quota and the retry time in HTTP response headers
### HtmlSanitizer - `symfony/html-sanitizer`
Cleans untrusted HTML so it keeps only the elements, attributes and URLs you allow, to prevent XSS and keep markup consistent. [Documentation](https://symfony.com/doc/8.1/html_sanitizer.html)
- Clean HTML from a rich text editor before you store it or show it
- Allow links only to approved schemes and hosts, and force safe attributes on each link
- Sanitize HTML in a form field automatically when the form is submitted
- Limit user content to a small set of tags, so that your CSS can style it consistently
- When you show HTML from an external source inside your own pages
### Ldap - `symfony/ldap`
Connects to LDAP directory servers to bind, search and change entries, and lets the Security component log users in against LDAP. [Documentation](https://symfony.com/doc/8.1/security/ldap.html)
- Log users in with their directory credentials through a login form, HTTP Basic or JSON login
- Load user data and roles from a directory while you check passwords from another source
- Search a directory for users or groups, for example to sync accounts into a database
- Create, update or delete directory entries from an administration tool
- Map directory group membership to application roles

## Data, validation and serialization

### Validator - `symfony/validator`
Checks objects and values against declared rules, such as not blank, valid email or value in range, and returns a list of violations. [Documentation](https://symfony.com/doc/8.1/validation.html)
- Validate request data or form data before you save it or pass it to a service
- Check a single value or array, such as a console answer or an option, against a set of rules
- Apply different rules to the same object in different contexts with validation groups
- Reject weak or compromised passwords, or check IBAN, card number and ISBN values
- Add rules to a class from a third-party library that you cannot change
### Form - `symfony/form`
Builds, renders, submits and validates HTML forms, and maps data between form fields and objects or arrays in both directions. [Documentation](https://symfony.com/doc/8.1/forms.html)
- Map an HTML form to an object, validate the submitted data and show errors next to each field
- Reuse one form definition, such as an address, inside many larger forms
- Split a long form into a flow of several steps
- Apply a partial PATCH update to an object, and keep the fields that the client did not send
- When forms need CSRF protection and consistent HTML from themes for a CSS framework
### Serializer - `symfony/serializer`
Converts objects to and from JSON, XML, CSV or YAML through an array step, with control over field names, groups and nested objects. [Documentation](https://symfony.com/doc/8.1/serializer.html)
- Turn a JSON request body into a typed PHP object and turn objects back into a JSON response
- Map a third-party API response with nested or differently named fields onto your own classes
- Expose different sets of properties of the same object to different API clients
- Update an existing object from a partial payload instead of creating a new one
- Load CSV data as PHP objects, or convert data from one format to another, such as YAML to XML
### JsonStreamer - `symfony/json-streamer`
Writes PHP data objects to JSON streams and reads JSON streams back into objects, fast and without loading all data into memory. [Documentation](https://symfony.com/doc/8.1/serializer/streaming_json.html)
- Read a very large JSON file or payload without loading it all into memory
- Write a large JSON API response as a stream from plain data objects
- Decode an incoming JSON request body into typed data objects quickly
- Rename JSON keys or transform values, such as dates, during encoding and decoding
- Encode a value object, such as a money amount, as a single JSON value
### JsonPath - `symfony/json-path`
Queries and extracts values from JSON documents with JSONPath expressions as defined in RFC 9535. [Documentation](https://symfony.com/doc/8.1/components/json_path.html)
- Extract specific values from a large or deeply nested API response
- Filter JSON array items by a condition without decoding and looping by hand
- Assert values in a JSON response in tests with JSONPath expressions
- Build query paths in code with automatic escaping of keys and values
- When the built-in filter functions are not sufficient, register a custom filter function
### ObjectMapper - `symfony/object-mapper`
Maps one object to another with attributes, for example from a request data object to an entity or from an entity to an API resource. [Documentation](https://symfony.com/doc/8.1/object_mapper.html)
- Copy data from a validated request object into a new or existing entity
- Build different output objects, such as public and admin views, from one source object
- Map snake_case data from an external API into camelCase application objects
- Apply a partial update that skips null values
- Flatten nested input objects into one target object
### PropertyAccess - `symfony/property-access`
Reads and writes values in objects and arrays through a string path, with getters, setters, adders, removers or public properties. [Documentation](https://symfony.com/doc/8.1/components/property_access.html)
- Read a nested value from an object graph or an array through one string path
- Map columns of an imported file to object properties when the target paths come from configuration
- Update a collection property through its adder and remover methods instead of replacing the array
- Check if a property path is readable or writable before you access it
- Read through paths that can contain null objects without an exception, with the nullsafe notation
### PropertyInfo - `symfony/property-info`
Extracts metadata about class properties: names, types, descriptions, and if they are readable, writable or set through the constructor. [Documentation](https://symfony.com/doc/8.1/components/property_info.html)
- Find the type of a property, including collection item types from PHPDoc, to convert raw input to objects
- List the properties of a class to build generic exports, forms or API documentation
- Check which properties a class exposes for read or write through getters, setters or public access
- Find which properties a class accepts as constructor arguments
- Combine metadata from reflection, PHPDoc, serializer groups and ORM mapping into one answer
### TypeInfo - `symfony/type-info`
Reads PHP types from properties, arguments, return values, PHPDoc or strings, and gives one type model for unions, generics and array shapes. [Documentation](https://symfony.com/doc/8.1/components/type_info.html)
- Find the exact type of a property, including PHPDoc generics, before you map data onto it
- Check if a value matches a declared type before you assign or store it
- Read array shapes and type aliases from PHPDoc to describe nested data
- When a library inspects classes, describe each type with one object instead of parsing type strings
### OptionsResolver - `symfony/options-resolver`
Turns a raw options array into a validated set, with required options, default values, type and value checks, and normalization. [Documentation](https://symfony.com/doc/8.1/components/options_resolver.html)
- Validate the options array that a reusable service, client or library class accepts
- Reject unknown or misspelled option keys instead of ignoring them silently
- Compute a default value from another option, for example a port that depends on the encryption mode
- Validate a list of repeated nested settings, such as many named connections with the same keys
- Deprecate an old option so that callers get a deprecation message when they still use it
### Yaml - `symfony/yaml`
Parses YAML strings and files into PHP values and dumps PHP data as YAML, with error messages that give the line of the problem. [Documentation](https://symfony.com/doc/8.1/components/yaml.html)
- Read configuration, fixtures or seed data that people write and maintain in YAML files
- Export PHP data as readable YAML, for example to generate a configuration file
- Parse YAML from untrusted sources with limits on aliases that stop memory exhaustion attacks
- Check the syntax of YAML files in a continuous integration step with a lint command
- Load trusted YAML that refers to PHP constants, enumerations or custom tags
### Uid - `symfony/uid`
Generates, parses and converts unique identifiers such as UUIDs and ULIDs, in text and binary forms. [Documentation](https://symfony.com/doc/8.1/components/uid.html)
- Give database records time-ordered identifiers that keep indexes efficient
- Create unique identifiers on several servers without a central counter
- Derive the same UUID from the same name every time, for example from a URL
- Shorten an identifier for URLs with its base58 or base32 form
- Make generated identifiers predictable in tests

## Background work and integration

### Messenger - `symfony/messenger`
Dispatches messages to handlers, at once or through queue transports that background workers consume, with retries and failure handling. [Documentation](https://symfony.com/doc/8.1/messenger.html)
- Move slow work, such as image processing or report generation, out of the web request
- Retry failed background tasks with a delay, and keep messages that still fail for review
- Exchange messages with other applications through a message broker
- Separate command, query and event buses in a CQRS design
- Run a console command or an external process in the background by dispatching a message
### Scheduler - `symfony/scheduler`
Generates recurring messages on cron or periodic schedules inside the application, and processes them through Messenger workers. [Documentation](https://symfony.com/doc/8.1/scheduler.html)
- Run maintenance tasks, such as database cleanup or cache clearing, every night
- Send recurring reports or notifications on a fixed schedule
- When schedules depend on data, add or remove recurring tasks at runtime
- Spread many tasks over time with random jitter or hashed cron expressions to avoid load peaks
- Replace server cron entries with schedules that the application defines and controls
### HttpClient - `symfony/http-client`
Sends HTTP requests to APIs and other servers, asynchronous by default, with streaming, concurrency, retries, caching and test tools. [Documentation](https://symfony.com/doc/8.1/http_client.html)
- Call external APIs with base URLs, headers and credentials per host kept in configuration
- Send many requests in parallel and process each response as soon as it arrives
- Retry failed requests, cache responses or limit the request rate to an external service
- Fetch URLs that users supply, and block access to private network addresses
- Test code that calls APIs with mock or recorded responses, without network access
### Webhook - `symfony/webhook`
Receives incoming webhooks, verifies and converts them into remote events, and sends signed webhook callbacks to subscriber endpoints. [Documentation](https://symfony.com/doc/8.1/webhook.html)
- Receive delivery or engagement events from an email provider, or status events from an SMS provider
- Verify the signature of an incoming webhook before the application trusts its payload
- Notify external subscribers with signed HTTP callbacks when an event occurs in the application
- Process incoming webhook events asynchronously through the message bus
- When two applications must exchange events over HTTP with a shared secret
### RemoteEvent - `symfony/remote-event`
Represents an event from or for a remote system as an object with a name, ID and payload, and sends it to consumer services. [Documentation](https://symfony.com/doc/8.1/webhook.html)
- Handle incoming webhook calls from payment, mail or SMS services in your own consumer code
- Track email delivery and engagement events that a mail service reports back to the application
- Track SMS delivery status updates that an SMS service sends to the application
- Process incoming events asynchronously through a message queue so that the webhook response stays fast
- Describe outgoing events with a name, ID and payload when you send webhooks to subscribers
### Mailer - `symfony/mailer`
Creates and sends emails over SMTP, sendmail or third-party provider APIs, with templates, attachments, signing and async sending. [Documentation](https://symfony.com/doc/8.1/mailer.html)
- Send account emails, such as sign-up confirmation or password reset, from templates
- Send emails in the background through a queue so that web requests stay fast
- Switch to a backup mail provider automatically when the main provider fails
- Sign or encrypt outgoing emails with DKIM or S/MIME
- Send all emails to one test address, or stop delivery, in development and tests
### Mime - `symfony/mime`
Creates and changes MIME messages such as emails, and detects MIME types and file extensions. [Documentation](https://symfony.com/doc/8.1/mailer.html)
- Build multipart emails with text, HTML, attachments and embedded images
- Detect the MIME type of an uploaded file, or find the file extensions for a MIME type
- Store an email message as data and send it later, for example from a queue
- Offer a prepared email as a draft file that email clients can open
- Add custom headers, such as tags or metadata, to an email message
### Notifier - `symfony/notifier`
Sends notifications to users through email, SMS, chat, push, desktop and browser channels, with many third-party provider transports. [Documentation](https://symfony.com/doc/8.1/notifier.html)
- Alert a user by SMS and email from one call, based on how important the event is
- Post a message to a team chat channel when an important event occurs
- Show a browser flash message with the same code that sends other notifications
- Send mobile or browser push notifications through a provider
- Use a backup SMS or chat provider when the main provider fails

## Concurrency, caching and state

### Lock - `symfony/lock`
Gives exclusive or shared access to a resource across processes and servers, with locks kept in files, databases or memory stores. [Documentation](https://symfony.com/doc/8.1/components/lock.html)
- Stop a console command or scheduled job from running twice at the same time
- Prevent two requests or workers from changing the same record or file at once
- Let many readers access data in parallel while one writer updates it
- Expire a lock after a time limit so that a crashed process does not block a resource forever
- When a long job continues in another process, pass the lock to that process
### Semaphore - `symfony/semaphore`
Limits how many processes can use a shared resource at the same time. A shared store, such as Redis or a lock backend, holds the state. [Documentation](https://symfony.com/doc/8.1/components/semaphore.html)
- Limit how many workers generate heavy documents or reports at the same time
- When a downstream service accepts only a few parallel calls, cap the number of concurrent callers
- Acquire a slot before you dispatch a queued job and release it in the worker after the job ends
- Keep a slot across several HTTP requests for a long multi-step task
### Cache - `symfony/cache`
Stores results of expensive operations in cache pools on many backends, with tag-based invalidation and cache stampede protection. [Documentation](https://symfony.com/doc/8.1/cache.html)
- Cache the result of a slow database query or external HTTP call for a set time
- Invalidate all cached entries that relate to one record with cache tags
- Stop many requests from computing the same expired value at the same time
- Refresh cached values in a background worker while users still get the old value
- Encrypt or compress cached values before they go to the storage backend
### Workflow - `symfony/workflow`
Models the lifecycle of an object as places and transitions, as a workflow or a finite state machine, and controls which transitions can occur. [Documentation](https://symfony.com/doc/8.1/workflow.html)
- Control the status of an order, article or ticket and allow only valid status changes
- Block a transition with business rules or an expression, and show the reason to the user
- Run side effects such as notifications or audit logs when an object leaves or enters a state
- Track parallel steps where an object is in several places at the same time
- Show in a template only the actions that are currently allowed for an object
### Clock - `symfony/clock`
Decouples code from the system clock, so that tests can freeze or move the current time. [Documentation](https://symfony.com/doc/8.1/components/clock.html)
- Test expiry, deadline or schedule logic at a fixed date and time
- Move time forward in a test without real waiting
- Measure elapsed time precisely, unaffected by changes to the system clock
- Calculate relative dates such as "+3 hours" from the current application time
- When a service needs the current time, inject a clock instead of creating a date object

## Files and processes

### Filesystem - `symfony/filesystem`
Gives platform-independent file and directory operations and path utilities that throw exceptions when an operation fails. [Documentation](https://symfony.com/doc/8.1/components/filesystem.html)
- Write a file atomically, so readers never see a half-written file
- Copy a directory tree to a target, and delete target files that are not in the source
- Accept absolute and relative paths from configuration and resolve them against a base directory
- Join and normalize paths, so the same code works on UNIX and Windows
- Create a unique temporary file, nested directories or symbolic links in one call
### Finder - `symfony/finder`
Finds files and directories with chained filters such as name, content, size, date, depth and path, and sorts the results. [Documentation](https://symfony.com/doc/8.1/components/finder.html)
- Find old files by modification date to clean up log, export or cache directories
- Search source files for a text or a regular expression, for example in a code generator or a checker
- Collect files for a build or deploy step, and skip version control files and paths in .gitignore
- Process all files of one type in an import directory, sorted by name or date
- When a directory tree is large and you must prune whole directories early
### Process - `symfony/process`
Runs external commands in sub-processes with safe argument escaping, timeouts, signals, real-time output and environment control. [Documentation](https://symfony.com/doc/8.1/components/process.html)
- Run a CLI tool, such as an image converter or a backup utility, instead of calling exec or shell_exec
- Stream the output of a long command to the user while the command runs
- Start several commands in parallel and wait until all of them finish
- Stop a command that runs too long or that produces no output for a given time
- Run PHP code in an isolated child process, optionally with the same PHP settings as the parent

## Text and languages

### String - `symfony/string`
Gives an object API for text as bytes, code points or grapheme clusters, so that changes to Unicode text in any language stay correct. [Documentation](https://symfony.com/doc/8.1/string.html)
- Make URL-safe slugs or safe file names from user text in any language
- Truncate, wrap or pad multilingual text without breaking characters or words
- Convert identifiers between camelCase, snake_case and other cases
- Convert English, French or Spanish words between singular and plural in code generation
- Measure the display width of text to align columns in a terminal
### Translation - `symfony/translation`
Translates application messages into the language of the user from catalog files, with placeholders, plurals and fallback locales. [Documentation](https://symfony.com/doc/8.1/translation.html)
- Show interface text, emails and error messages in the language of each user
- Find missing or unused translations and extract new messages into translation files
- Push and pull translation files to and from an external translation service
- Render content in a different language for a short time, for example in a console command
- Keep a message in an enum or value object and translate it later where it is shown
### Intl - `symfony/intl`
Gives localized names and ISO codes for languages, scripts, countries, locales, currencies and timezones from ICU data. [Documentation](https://symfony.com/doc/8.1/components/intl.html)
- Show country, language or currency names in the language of the current user
- Validate ISO codes, or convert them, for example two-letter to three-letter country codes
- List the timezones of a country, or show the offset of a timezone to a user
- Get the decimal digits and rounding increment of a currency before you format money
- When a user picks a currency, list only the currencies that are legal tender in a country
### Emoji - `symfony/emoji`
Converts emoji to text names in many languages, converts emoji to and from chat short codes, or removes emoji, based on Unicode CLDR data. [Documentation](https://symfony.com/doc/8.1/emoji.html)
- Remove all emoji from user input before you store it or send it to a plain-text channel
- Replace emoji with their names, for example to make readable URL slugs or search terms
- Convert short codes such as :+1: into emoji characters in comments or chat messages
- Convert emoji to short codes before you send text to a system that cannot show emoji
- When an export or a search index needs words in a given language instead of emoji characters

## Application structure and configuration

### DependencyInjection - `symfony/dependency-injection`
Standardizes and centralizes how objects are built: a service container creates services and injects their dependencies. [Documentation](https://symfony.com/doc/8.1/service_container.html)
- Let the container build services and pass constructor dependencies automatically through autowiring
- Inject configuration values, parameters, environment variables or expressions into a service
- Choose among several implementations of one interface with named aliases or explicit wiring
- Select one of several services at runtime by a key with a service locator
- Build a compiled and cached container for a standalone application or a CLI tool
### Config - `symfony/config`
Finds, loads, merges and validates configuration values from files or other sources, with defaults and type rules. [Documentation](https://symfony.com/doc/8.1/components/config.html)
- Give a reusable bundle or library a validated configuration with defaults, types and allowed values
- Merge configuration from several files so that later files override earlier values
- Load configuration files in your own format through a pluggable loader
- Generate a PHP file from configuration and regenerate it only when a source file changes
- Find a file in a list of directories in priority order
### Dotenv - `symfony/dotenv`
Reads .env files into environment variables, with override files per machine and per environment, so configuration stays out of code. [Documentation](https://symfony.com/doc/8.1/configuration.html#configuring-environment-variables-in-env-files)
- Keep default configuration values in a committed file and override them per machine or per environment
- Load .env files in a standalone script or application that does not use the full framework
- Compile all .env files into one PHP file at deploy time, so production does not parse them on each request
- When you must find out which .env file sets the final value of an environment variable
### EventDispatcher - `symfony/event-dispatcher`
Lets parts of an application communicate through events: one part dispatches an event, and any number of listeners react to it. [Documentation](https://symfony.com/doc/8.1/event_dispatcher.html)
- Send emails, update statistics or notify other services after an action, without changes to that action
- Run checks before a controller executes, or add headers to each response after it
- Let other code change data or stop the process before a method continues or returns
- Add extension points to a library, so its users can add behavior without inheritance
- When you must see which listeners ran for an event, and in which order
### ExpressionLanguage - `symfony/expression-language`
Evaluates or compiles one-line expressions in a restricted, PHP-like syntax, so rules can live in configuration or data instead of code. [Documentation](https://symfony.com/doc/8.1/expression_language.html)
- Store business rules, such as discount or eligibility conditions, in the database for non-developers to edit
- Evaluate user-defined conditions in a sandbox where only the variables and functions you declare exist
- Lint the syntax of a rule before you save it
- Compile frequently used rules to PHP and cache the parsed result for speed
- Inspect or change a stored rule through its syntax tree instead of string manipulation
### Contracts - `symfony/contracts`
Provides small sets of interfaces extracted from Symfony components, for loose coupling and interoperability between implementations. [Documentation](https://symfony.com/doc/8.1/components/contracts.html)
- Type-hint a cache, HTTP client, event dispatcher or translator interface, not a concrete class
- When writing a library, depend only on a small contracts package, not on a full component
- Declare that your package implements a contract, so that other packages can rely on it
- Mark a class with a labeling interface so that autoconfiguration applies a behavior to it
### VarExporter - `symfony/var-exporter`
Works with object internals: exports data to fast plain PHP code, deep-clones values, and creates or fills objects without their constructor. [Documentation](https://symfony.com/doc/8.1/components/var_exporter.html)
- Cache computed configuration or metadata as PHP code that OPcache loads fast
- Make a deep copy of an object graph with less memory than serialize and unserialize
- Create or fill objects, including private and readonly properties, without calling the constructor
- Create a lazy proxy for an interface or abstract class when native lazy objects do not work
- Clone many independent copies of one prototype object fast

## Command line

### Console - `symfony/console`
Creates testable command-line commands and tools, with arguments, options, prompts, progress bars, tables and styled output. [Documentation](https://symfony.com/doc/8.1/console.html)
- Write a maintenance or import task that an operator or a scheduler runs from the CLI
- Ask the user questions, show progress bars and render tables in a terminal tool
- Handle termination signals in a long-running command to finish work cleanly
- Build a standalone CLI tool or a single-command application outside a web framework
- Run logic before or after every command, such as logging or error handling, with console events
### Tui - `symfony/tui`
Builds interactive terminal user interfaces from widgets, with CSS-like styles, keyboard focus and events. The component is experimental. [Documentation](https://symfony.com/doc/8.1/tui/index.html)
- Build a console command with text inputs, a multi-line editor and select lists
- Show a progress bar or a cancellable loader while a long CLI task runs
- Make a terminal settings screen where the user changes options with the keyboard
- Render Markdown content in the terminal
- When simple question-and-answer prompts are not enough, react to each key press in a CLI tool

## Testing, debugging and profiling

### BrowserKit - `symfony/browser-kit`
Simulates a web browser in PHP: it makes requests, clicks links, submits forms and keeps cookies and history. [Documentation](https://symfony.com/doc/8.1/components/browser_kit.html)
- Write functional tests that click links and submit forms in your own application
- Fetch pages from an external website and extract data without a separate scraper library
- Log in to a remote site through its HTML form and keep the session cookies for later requests
- Send JSON or AJAX-style requests in tests and read the response as a PHP array
- Simulate back and forward navigation through the request history
### DomCrawler - `symfony/dom-crawler`
Navigates and queries HTML and XML documents, and reads their links, images and forms. [Documentation](https://symfony.com/doc/8.1/components/dom_crawler.html)
- Extract text, attributes or lists of values from an HTML page or an XML feed
- Check page content, follow links and submit forms in functional tests
- Read the fields, action URL and method of a form to build the matching request
- Resolve relative link URLs against a base URL
- Query XML documents that use namespaces without manual namespace registration
### CssSelector - `symfony/css-selector`
Converts CSS selectors to XPath expressions, so that code can find elements in HTML or XML documents with familiar syntax. [Documentation](https://symfony.com/doc/8.1/components/css_selector.html)
- Find elements in an HTML or XML document with a CSS selector instead of a long XPath expression
- Filter page nodes with CSS selectors when you crawl pages in functional tests
- Generate an XPath query from a CSS selector for use with the native PHP DOM and XML tools
- When users or configuration supply CSS selectors, run them against documents on the server
### PHPUnit Bridge - `symfony/phpunit-bridge`
Adds PHPUnit utilities that report deprecated code in tests and mock time, DNS and class existence functions. [Documentation](https://symfony.com/doc/8.1/components/phpunit_bridge.html)
- Report deprecated code that the test suite triggers, and fail the build above a set threshold
- Test code that calls sleep() or time() without slow waits or random failures
- Test DNS-dependent validation without real network connections
- Test the fallback behavior when an optional class or dependency is not installed
- Record a baseline of known deprecations and report only the new ones
### VarDumper - `symfony/var-dumper`
Inspects any PHP variable and shows its full structure as readable HTML or console output. It is a better replacement for var_dump(). [Documentation](https://symfony.com/doc/8.1/components/var_dumper.html)
- Inspect a complex object or nested array during development, in the browser or the terminal
- Send debug output to a separate dump server so that it does not break the response or command output
- Capture the dump of a variable as a string to write it to a log or a file
- Assert the full structure of a value in a test by comparing its dump
- Control how objects of a class appear in dumps, for example to hide noisy internals
### Stopwatch - `symfony/stopwatch`
Measures the time and memory that named parts of your code use, and shows the results in the Symfony profiler timeline. [Documentation](https://symfony.com/doc/8.1/performance.html#profiling-with-symfony-stopwatch)
- Find which step of a slow request, export or import uses the most time
- Record each iteration of a batch loop as a lap to find the slow records
- Group timings into sections for the phases of a long process
- Time the rendering of one block in a template
- Print a short time and memory summary of a code block in a console command or a log

## Bridges

### Doctrine Bridge - `symfony/doctrine-bridge`
Connects Doctrine to Symfony forms, validation, security, Messenger and controllers, and adds UUID, ULID and date point column types. [Documentation](https://symfony.com/doc/8.1/doctrine.html)
- Load an entity from route parameters into a controller argument, with a 404 response when it is missing
- Reject a new record when a field value, such as an email address, already exists in the database
- Show a select field with options that come from database entities
- Load the users who log in from database entities
- Wrap message handlers in one transaction and keep database connections of long-running workers healthy
### Twig Bridge - `symfony/twig-bridge`
Connects Twig to Symfony with template functions, filters and tags for routing, assets, forms, security, translation, workflows and more. [Documentation](https://symfony.com/doc/8.1/templates.html)
- Generate URLs, asset paths and translated text in templates without hard-coded values
- Render forms, and show parts of a page only when the user has the required permission
- Render the HTML body of an email from a template
- Check the syntax of all templates in continuous integration before a deployment
- Validate that template content that users submit has valid Twig syntax
### Monolog Bridge - `symfony/monolog-bridge`
Connects Monolog to Symfony with handlers and processors for console output, email alerts and request, route or user context in logs. [Documentation](https://symfony.com/doc/8.1/logging.html)
- Show log messages in command output at the level that matches the verbosity of the command
- Send an email with all log messages of a request when a critical error occurs
- Add the current route, controller and route parameters to each log record
- Add the authenticated user, or the user who impersonates them, to each log record
