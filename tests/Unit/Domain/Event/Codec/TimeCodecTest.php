<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event\Codec;

use App\Domain\Event\Codec\TimeCodec;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TimeCodecTest extends KernelTestCase
{
    #[TestDox('Refuses to write a time after 9999-12-31T23:59:59Z')]
    public function testRefusesAFiveDigitYear(): void
    {
        $this->expectException(\LogicException::class);

        TimeCodec::encode(new \DateTimeImmutable('@253402300800'));
    }
}
