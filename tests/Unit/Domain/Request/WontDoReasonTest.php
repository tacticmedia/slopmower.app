<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Request\WontDoReason;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WontDoReasonTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('Rejects a reason with fewer than 20 characters after trimming')]
    #[DataProvider('shortReasons')]
    public function testRejectsShortReasons(string $reason): void
    {
        self::assertInvalidInput(['reason'], static fn () => WontDoReason::fromString($reason));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shortReasons(): iterable
    {
        yield '19 characters padded with whitespace' => ["  \t".str_repeat('a', 19)."\n  "];
        yield '19 two-byte characters' => [str_repeat('ü', 19)];
    }

    #[TestDox('Accepts 20 characters after trimming and keeps the text as written')]
    public function testAcceptsTwentyCharactersAndKeepsTheText(): void
    {
        $reason = ' '.str_repeat('a', 20)."\n";

        self::assertSame($reason, WontDoReason::fromString($reason)->toString());
    }
}
