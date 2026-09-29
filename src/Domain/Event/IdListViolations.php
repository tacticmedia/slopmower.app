<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\Error\Violation;
use App\Domain\Identity\UlidId;

/**
 * Payload ID lists are sets, as `requestIds` of event.schema.json (`uniqueItems`).
 */
final class IdListViolations
{
    /**
     * @param list<UlidId> $ids
     *
     * @return list<Violation>
     */
    public static function nonEmptySet(array $ids, string $field): array
    {
        return [] === $ids ? [new Violation($field, 'must hold at least one ID')] : self::set($ids, $field);
    }

    /**
     * @param list<UlidId> $ids
     *
     * @return list<Violation>
     */
    public static function set(array $ids, string $field): array
    {
        $values = array_map(strval(...), $ids);

        return \count(array_unique($values)) === \count($values) ? [] : [new Violation($field, 'must not hold the same ID twice')];
    }
}
