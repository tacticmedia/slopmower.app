<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Event\Codec\PayloadJson;
use App\Domain\Event\EventType;

/**
 * `tests/Fixtures/events/<type>.json`: the exact stored payload text of one representative event per type.
 */
final class EventFixtures
{
    public static function json(EventType $type): string
    {
        $path = \dirname(__DIR__, 3).'/Fixtures/events/'.$type->value.'.json';
        $json = file_get_contents($path);

        return false !== $json ? $json : throw new \RuntimeException('Cannot read '.$path);
    }

    /**
     * @return array<mixed>
     */
    public static function payload(EventType $type): array
    {
        return PayloadJson::decode(self::json($type));
    }
}
