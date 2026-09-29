<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Error\InvalidInput;
use App\Domain\Error\Violation;

trait ViolatedFields
{
    /**
     * @return list<string>
     */
    private static function violatedFields(\Closure $build): array
    {
        try {
            $build();
        } catch (InvalidInput $invalidInput) {
            return array_map(static fn (Violation $violation): string => $violation->field, $invalidInput->violations());
        }

        self::fail('InvalidInput was not thrown.');
    }
}
