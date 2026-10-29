<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KernelTimezoneTest extends KernelTestCase
{
    #[TestDox('The kernel reads and writes times in UTC, whatever timezone the host php.ini sets')]
    public function testBootSetsUtc(): void
    {
        $hostTimezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            self::bootKernel();
            $timezone = date_default_timezone_get();
        } finally {
            date_default_timezone_set($hostTimezone);
        }

        self::assertSame('UTC', $timezone);
    }
}
