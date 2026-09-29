# M28 Re-anchoring core

Workflow, conventions, and status: [README](README.md).

## Goal

The pure functions of architecture section 5.4, on line arrays, without git.

## Depends on

M02.

## In scope

- `UnifiedZeroDiffParser`: parses the hunks of `git diff --unified=0`.
- `RangeMapper`: `mapRange` maps each boundary line through the hunks. A line before a hunk moves by the sum of the earlier deltas. A line inside a hunk maps to the new side of that hunk. The result says whether the range is untouched and whether it keeps some lines.
- `SnapshotMatcher`:
  - a snapshot is distinctive when it has 8 or more non-whitespace characters;
  - finds exact matches in the comparison form of architecture section 5.1 and picks the one nearest to the mapped start. For a truncated snapshot, a match is a window of `lineCount` lines whose hash equals `normalizedHash`;
  - detects ties.
- `LineSimilarity`: the formula of architecture section 5.4, `2 * m / (|a| + |b|)` with `m` the longest common subsequence of lines in comparison form.
- `BestWindow`, with the threshold 0.6.

## Out of scope

- Git access: M29.

## Files (representative)

- `src/Domain/Anchoring/*.php`.
- `tests/Unit/Domain/Anchoring/*Test.php`.

## Tasks (ordered)

1. Implement the functions as pure PHP in `src/Domain/Anchoring`. The M02 purity test covers them.
2. Write the tests from the cases of architecture section 5.5.

## Acceptance tests

All are unit tests.

- **U `UnifiedZeroDiffParserTest`** proves the hunk parser.
  - "Parses added, deleted, and changed lines"
  - "Parses several hunks"
  - "Handles a file without a newline at the end"
- **U `RangeMapperTest`** proves the diff mapping.
  - "F4: lines added or removed above the region shift the range"
  - "F5: an edited region maps to the new side with some lines kept"
- **U `SnapshotMatcherTest`** proves the content search.
  - "F9: two matches at equal distance are ambiguous"
  - "F10: a trivial snapshot is never searched"
  - "F11: a whitespace or line-ending change still matches"
  - "A truncated snapshot matches a window with its normalizedHash"
- **U `LineSimilarityTest`** proves the similarity measure.
  - "Identical lists give 1, disjoint lists give 0, and 3 common lines of 4 and 6 give 0.6"
- **U `BestWindowTest`** proves the fuzzy search.
  - "Finds the best window near the mapped start"
  - "A score below 0.6 gives no window"

## Verification commands

```sh
make test
make check
```

## Done

The functions reproduce the pure cases F4, F5, F9, F10, and F11.
