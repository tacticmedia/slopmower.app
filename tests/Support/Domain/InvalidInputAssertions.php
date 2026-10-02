<?php

declare(strict_types=1);

namespace App\Tests\Support\Domain;

use App\Domain\Error\InvalidInput;

trait InvalidInputAssertions
{
    /**
     * @param list<string> $expectedFields
     */
    private static function assertInvalidInput(array $expectedFields, callable $build): void
    {
        try {
            $build();
        } catch (InvalidInput $error) {
            self::assertSame($expectedFields, array_column($error->details()['violations'], 'field'));

            return;
        }

        self::fail('No InvalidInput was thrown.');
    }
}
