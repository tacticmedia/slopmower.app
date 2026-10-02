<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Error\InvalidInput;

/**
 * The opaque cursor of docs/specs/README.md section 5: base64url JSON of the last sort key and a hash of the filters.
 * The filters are every query parameter except `cursor` and `limit`.
 */
final class Cursor
{
    private const string FIELD = 'cursor';

    /** Enough to tell filters apart. The cursor is not a secret. */
    private const int FILTER_HASH_CHARACTERS = 16;

    /**
     * @param list<string|int>                $sortKey
     * @param array<string, string|bool|null> $filters
     */
    public static function encode(array $sortKey, array $filters): string
    {
        $json = json_encode(['k' => $sortKey, 'f' => self::filterHash($filters)], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @param array<string, string|bool|null> $filters
     *
     * @return list<mixed> The sort key, which the caller checks
     *
     * @throws InvalidInput A malformed cursor, or a cursor of other filters
     */
    public static function decode(string $cursor, array $filters): array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $decoded = false === $json ? null : json_decode($json, true);

        if (!\is_array($decoded) || !isset($decoded['k'], $decoded['f']) || !\is_array($decoded['k']) || !array_is_list($decoded['k'])) {
            throw self::malformed();
        }

        if ($decoded['f'] !== self::filterHash($filters)) {
            throw InvalidInput::field(self::FIELD, 'The cursor belongs to other filters.');
        }

        return $decoded['k'];
    }

    public static function malformed(): InvalidInput
    {
        return InvalidInput::field(self::FIELD, 'The cursor is malformed.');
    }

    /**
     * @param array<string, string|bool|null> $filters
     */
    private static function filterHash(array $filters): string
    {
        ksort($filters);

        return substr(hash('sha256', json_encode($filters, \JSON_THROW_ON_ERROR)), 0, self::FILTER_HASH_CHARACTERS);
    }
}
