<?php

declare(strict_types=1);

namespace App\Tests\Support\Domain;

use App\Domain\Event\Codec\PayloadJson;

trait EventFixtures
{
    /**
     * @param string $name File name in tests/Fixtures/events without ".json"
     */
    private static function eventFixtureJson(string $name): string
    {
        return rtrim((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/events/{$name}.json"), "\n");
    }

    /**
     * @return array<mixed>
     */
    private static function eventFixturePayload(string $name): array
    {
        return PayloadJson::decode(self::eventFixtureJson($name));
    }
}
