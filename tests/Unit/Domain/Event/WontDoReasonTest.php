<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event;

use App\Domain\Event\RequestWontDo;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WontDoReasonTest extends KernelTestCase
{
    use ViolatedFields;

    /**
     * @return iterable<string, array{string}>
     */
    public static function shortReasons(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => [str_repeat(' ', 40)];
        yield '19 characters' => [str_repeat('a', 19)];
        yield '19 characters inside spaces' => ['   '.str_repeat('a', 19).'   '];
        yield '19 characters inside tabs and line breaks' => ["\t\n".str_repeat('a', 19)."\r\n"];
        yield '19 characters inside Unicode spaces' => ["\u{00A0}".str_repeat('a', 19)."\u{3000}"];
        yield '19 two-byte characters' => [str_repeat("\u{00E9}", 19)];
    }

    #[DataProvider('shortReasons')]
    #[TestDox('Rejects a reason with fewer than 20 characters after trimming')]
    public function testRejectsAShortReason(string $reason): void
    {
        self::assertSame(['reason'], self::violatedFields(static fn (): RequestWontDo => new RequestWontDo($reason)));
        self::assertSame(['payload.reason'], self::violatedFields(static fn (): RequestWontDo => RequestWontDo::fromPayload(['reason' => $reason])));
    }

    #[TestDox('Accepts a reason of 20 characters after trimming and keeps it untrimmed')]
    public function testAcceptsAReasonOf20Characters(): void
    {
        $reason = ' '.str_repeat("\u{00E9}", 20)."\n";

        self::assertSame($reason, new RequestWontDo($reason)->reason);
        self::assertSame($reason, RequestWontDo::fromPayload(['reason' => $reason])->reason);
    }
}
