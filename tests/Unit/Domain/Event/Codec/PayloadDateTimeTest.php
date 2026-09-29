<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event\Codec;

use App\Domain\Event\Codec\PayloadDateTime;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PayloadDateTimeTest extends KernelTestCase
{
    use ViolatedFields;

    /**
     * @return iterable<string, array{?\DateTimeImmutable, ?string}>
     */
    public static function dateTimes(): iterable
    {
        yield 'offset' => [new \DateTimeImmutable('2026-09-29T19:41:12+09:30'), '2026-09-29T10:11:12Z'];
        yield 'Unix timestamp' => [new \DateTimeImmutable('@1790676672'), '2026-09-29T10:11:12Z'];
        yield 'null' => [null, null];
    }

    #[DataProvider('dateTimes')]
    #[TestDox('Formats a date-time in UTC with second precision and a Z suffix, and null as null')]
    public function testFormatsInUtcWithAZSuffix(?\DateTimeImmutable $dateTime, ?string $expected): void
    {
        self::assertSame($expected, PayloadDateTime::format($dateTime));
    }

    #[TestDox('Refuses to format a fraction of a second, which the payload would lose')]
    public function testRefusesToFormatAFractionOfASecond(): void
    {
        $this->expectException(\LogicException::class);

        PayloadDateTime::format(new \DateTimeImmutable('2026-09-29T10:11:12.000001Z'));
    }

    #[TestDox('Parses the format it writes into a UTC date-time')]
    public function testParsesTheFormatItWrites(): void
    {
        $dateTime = PayloadDateTime::parse('2026-09-29T10:11:12Z', 'committedAt');

        self::assertSame('2026-09-29 10:11:12.000000 UTC', $dateTime->format('Y-m-d H:i:s.u e'));
        self::assertSame('2026-09-29T10:11:12Z', PayloadDateTime::format($dateTime));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherForms(): iterable
    {
        yield 'empty' => [''];
        yield 'date only' => ['2026-09-29'];
        yield 'no seconds' => ['2026-09-29T10:11Z'];
        yield 'zero offset' => ['2026-09-29T10:11:12+00:00'];
        yield 'other offset' => ['2026-09-29T19:41:12+09:30'];
        yield 'fraction' => ['2026-09-29T10:11:12.000Z'];
        yield 'lower-case z' => ['2026-09-29T10:11:12z'];
        yield 'lower-case t' => ['2026-09-29t10:11:12Z'];
        yield 'space separator' => ['2026-09-29 10:11:12Z'];
        yield 'February 30' => ['2026-02-30T10:11:12Z'];
        yield 'hour 24' => ['2026-09-29T24:00:00Z'];
        yield 'one-digit month' => ['2026-9-29T10:11:12Z'];
        yield 'leading space' => [' 2026-09-29T10:11:12Z'];
        yield 'trailing newline' => ["2026-09-29T10:11:12Z\n"];
    }

    #[DataProvider('otherForms')]
    #[TestDox('Rejects any other form, naming the field')]
    public function testRejectsAnyOtherForm(string $value): void
    {
        self::assertSame(['payload.committedAt'], self::violatedFields(static fn (): \DateTimeImmutable => PayloadDateTime::parse($value, 'payload.committedAt')));
    }
}
