<?php

declare(strict_types=1);

namespace App\Index;

/**
 * The match and the order of V16 in docs/specs/rest-api.md.
 *
 * @phpstan-type Member array{key: array{int, int, string, int, int, string}, candidate: array<string, mixed>}
 */
final class SearchRank
{
    private const string WORD_BOUNDARY = '/[^\p{L}\p{Nd}]+|(?<=[\p{Ll}\p{Nd}])(?=\p{Lu})|(?<=\p{Lu})(?=\p{Lu}\p{Ll})/u';
    private const string LETTERS_AND_DIGITS = '/^[\p{L}\p{Nd}]+$/uD';

    /**
     * Memory stays proportional to $limit: each group of order rule 1 keeps at most 2 * $limit members, and a candidate
     * is not tested for the groups after the first ones that already hold $limit members.
     *
     * @param iterable<array<string, mixed>> $candidates Rows with `kind`, `path`, `name`, `fqn`, and `declaration_start_line`.
     *                                                   `name` is the last path segment of a file, the short name of a symbol.
     *
     * @return list<array<string, mixed>> The first $limit candidates that match $query, in the V16 order
     */
    public static function top(iterable $candidates, string $query, int $limit): array
    {
        $lowerQuery = mb_strtolower($query);
        $matchesWordStarts = self::wordStartsMatcher($query);
        $groupTests = [
            static fn (array $candidate, string $lowerName): bool => $lowerName === $lowerQuery,
            static fn (array $candidate, string $lowerName): bool => str_starts_with($lowerName, $lowerQuery),
            static fn (array $candidate): bool => $matchesWordStarts((string) $candidate['name']),
            static fn (array $candidate, string $lowerName): bool => str_contains($lowerName, $lowerQuery),
            static fn (array $candidate): bool => 'file' === $candidate['kind'] && str_contains(mb_strtolower((string) $candidate['path']), $lowerQuery),
        ];
        $groups = array_fill(0, count($groupTests), []);
        $openGroups = count($groupTests);

        foreach ($candidates as $candidate) {
            $lowerName = mb_strtolower((string) $candidate['name']);

            for ($group = 0; $group < $openGroups; ++$group) {
                if ($groupTests[$group]($candidate, $lowerName)) {
                    $groups[$group][] = ['key' => self::sortKey($candidate), 'candidate' => $candidate];

                    if (count($groups[$group]) >= 2 * $limit) {
                        $groups[$group] = self::first($groups[$group], $limit);
                    }

                    $openGroups = self::openGroups($groups, $limit);
                    break;
                }
            }
        }

        $top = [];

        foreach ($groups as $members) {
            array_push($top, ...self::first($members, $limit - count($top)));
        }

        return array_column($top, 'candidate');
    }

    /**
     * @return \Closure(string): bool Whether a name matches $query by word starts
     */
    public static function wordStartsMatcher(string $query): \Closure
    {
        if (1 !== preg_match(self::LETTERS_AND_DIGITS, $query)) {
            return static fn (string $name): bool => false;
        }

        $lowerQuery = mb_strtolower($query);

        return static fn (string $name): bool => self::wordStarts($name, $lowerQuery);
    }

    /**
     * Order rules 2 to 5 within one group.
     *
     * @param array<string, mixed> $candidate
     *
     * @return array{int, int, string, int, int, string}
     */
    private static function sortKey(array $candidate): array
    {
        return [
            mb_strlen((string) $candidate['name']),
            mb_strlen((string) $candidate['path']),
            (string) $candidate['path'],
            'file' === $candidate['kind'] ? 0 : 1,
            (int) $candidate['declaration_start_line'],
            (string) $candidate['fqn'],
        ];
    }

    /**
     * @param list<Member> $members
     *
     * @return list<Member>
     */
    private static function first(array $members, int $count): array
    {
        usort($members, static fn (array $a, array $b): int => $a['key'][0] <=> $b['key'][0]
            ?: $a['key'][1] <=> $b['key'][1]
            ?: strcmp($a['key'][2], $b['key'][2])
            ?: $a['key'][3] <=> $b['key'][3]
            ?: $a['key'][4] <=> $b['key'][4]
            ?: strcmp($a['key'][5], $b['key'][5]));

        return array_slice($members, 0, $count);
    }

    /**
     * @param array<int, list<Member>> $groups
     *
     * @return int The count of groups, from the first, that a candidate can still join: the groups after those that
     *             together hold $limit members cannot reach the first $limit
     */
    private static function openGroups(array $groups, int $limit): int
    {
        $kept = 0;

        foreach ($groups as $index => $members) {
            $kept += count($members);

            if ($kept >= $limit) {
                return $index + 1;
            }
        }

        return count($groups);
    }

    /**
     * Compares bytes: a prefix that ends inside a character leaves a continuation byte at the start of the rest of the
     * query, and no word starts with one.
     *
     * @param string $lowerQuery Letters and digits only
     */
    private static function wordStarts(string $name, string $lowerQuery): bool
    {
        $words = array_map(mb_strtolower(...), preg_split(self::WORD_BOUNDARY, $name, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        $wordCount = count($words);
        $queryBytes = strlen($lowerQuery);
        // By the query offset that the prefixes reach: the lowest index of a word that can take the rest. A lower index
        // leaves more choices, so it is the only one kept.
        $nextWord = [0 => 0];

        for ($offset = 0; $offset < $queryBytes; ++$offset) {
            for ($word = $nextWord[$offset] ?? $wordCount; $word < $wordCount; ++$word) {
                $wordBytes = strlen($words[$word]);

                for ($length = 1; $length <= $wordBytes && $offset + $length <= $queryBytes && $words[$word][$length - 1] === $lowerQuery[$offset + $length - 1]; ++$length) {
                    $nextWord[$offset + $length] = min($nextWord[$offset + $length] ?? $word + 1, $word + 1);
                }
            }
        }

        return isset($nextWord[$queryBytes]);
    }
}
