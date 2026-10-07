# F12 Index job memory

Workflow, conventions, and status: [feature track](features.md). Hard rule: bugs and issues before features (`AGENTS.md`). Found by F11 on 2026-10-07.

## Goal

The symbol index job of a review finishes in one run, within the worker's memory limit, for a repository of this size and larger.

## Facts

- In the dev stack, the `IndexReview` job for this repository's review ran out of the worker's 128 MB memory at file 870 of 1,337. The second run finished.
- The job is `MessageHandler\IndexReviewHandler`. It lists the tree, inserts the file rows, then parses each new PHP blob with `BlobSymbols` and `PhpSymbolExtractor` (php-parser 5.9) and stores the symbols through `IndexSql`.
- The 128 MB limit and its source (PHP `memory_limit`, or a `messenger:consume --memory-limit`) must be confirmed, in `compose.yaml`, the Dockerfile, and the PHP ini files.

## In scope

- Reproduce the failure with this repository, or with a synthetic repository of the same shape. Measure the peak memory per file with Symfony AI Mate's log tools and `memory_get_peak_usage()`.
- Find what the job keeps between files, for example ASTs, parser or lexer state, Doctrine identity-map entries, collected symbol arrays, or a growing log context. Fix the cause, so that memory stays flat across files.
- Make a file whose parse fails keep its `parse_error` behaviour. The job's progress updates and its `index` live updates keep their rate.
- A memory limit is raised only if the measured flat usage still needs it. Record the number.

## Out of scope

Indexing languages other than PHP, and a change to the index format.

## Acceptance tests

- **F:** index a synthetic repository of at least 1,500 PHP files in one run in the test environment. Assert that peak memory growth after the first files stays under a bound that the old code exceeds. The bound comes from the measurement and is recorded in the report.
- Manual check: in the dev stack, re-index this repository's review with `app:index:rebuild` in one run, and record the peak memory in the report.
- `make check` is green.
