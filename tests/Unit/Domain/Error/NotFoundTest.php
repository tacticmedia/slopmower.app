<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Error;

use App\Domain\Error\NotFound;
use App\Domain\Identity\RequestId;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NotFoundTest extends KernelTestCase
{
    #[TestDox('Names the resource and its identifier in the message')]
    public function testNamesTheResourceAndItsIdentifier(): void
    {
        $error = NotFound::resource('Request', RequestId::fromString('01K6B3P2A9QRSTVWXYZ0123456'));

        self::assertSame('Request 01K6B3P2A9QRSTVWXYZ0123456 was not found.', $error->getMessage());
    }
}
