<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Error\InvalidInput;
use Symfony\Component\Uid\Ulid;

/**
 * The opaque cursor of docs/specs/README.md section 5: base64url JSON of the last sort key and a hash of the filters.
 * The filters are every query parameter except `cursor` and `limit`.
 */
final class Cursor
{
    private const string FIELD = 'cursor';

    /** Enough to tell filters apart. The cursor is not a secret. */
    private const int FILTER_HASH_CHARACTERS = 16;

    /** Years 1 to 9999, which TIMESTAMP(0) and the DBAL date format hold. */
    private const int EARLIEST_UNIX_SECONDS = -62135596800;
    private const int LATEST_UNIX_SECONDS = 253402300799;

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
     * @template T
     *
     * @param list<T>                         $rows    The rows of the page, then at least one more if a next page exists
     * @param array<string, string|bool|null> $filters
     * @param \Closure(T): list<string|int>   $sortKey
     *
     * @return string|null Null on the last page
     */
    public static function next(array $rows, int $limit, array $filters, \Closure $sortKey): ?string
    {
        return count($rows) > $limit ? self::encode($sortKey($rows[$limit - 1]), $filters) : null;
    }

    /**
     * @param array<string, string|bool|null> $filters
     * @param 'int'|'string'|'ulid'|'time'    ...$types One per sort key value. A ulid becomes a Ulid, a time in Unix
     *                                                  seconds a \DateTimeImmutable.
     *
     * @return list<mixed> The sort key
     *
     * @throws InvalidInput A malformed cursor, or a cursor of other filters
     */
    public static function decode(string $cursor, array $filters, string ...$types): array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $decoded = false === $json ? null : json_decode($json, true);

        if (!\is_array($decoded) || !isset($decoded['k'], $decoded['f']) || !\is_array($decoded['k']) || !array_is_list($decoded['k'])) {
            throw self::malformed();
        }

        if ($decoded['f'] !== self::filterHash($filters)) {
            throw InvalidInput::field(self::FIELD, 'The cursor belongs to other filters.');
        }

        if (count($decoded['k']) !== count($types)) {
            throw self::malformed();
        }

        return array_map(static fn (mixed $value, string $type): mixed => match (true) {
            'int' === $type && \is_int($value), 'string' === $type && \is_string($value) => $value,
            'ulid' === $type && \is_string($value) && Ulid::isValid($value) => Ulid::fromString($value),
            'time' === $type && \is_int($value) && $value >= self::EARLIEST_UNIX_SECONDS && $value <= self::LATEST_UNIX_SECONDS => new \DateTimeImmutable("@{$value}"),
            default => throw self::malformed(),
        }, $decoded['k'], $types);
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
