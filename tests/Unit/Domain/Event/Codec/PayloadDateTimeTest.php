<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event\Codec;

use App\Domain\Error\Violation;
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

    /**
     * @return iterable<string, array{\DateTimeImmutable}>
     */
    public static function yearsOutsideTheRange(): iterable
    {
        yield 'year 10000' => [new \DateTimeImmutable('@253402300800')];
        yield 'year -1' => [new \DateTimeImmutable('@-62167219201')];
        yield 'year 9999 with an offset that makes year 10000 in UTC' => [new \DateTimeImmutable('9999-12-31T23:59:59-01:00')];
    }

    #[DataProvider('yearsOutsideTheRange')]
    #[TestDox('Reports and refuses to format a UTC year outside 0000-9999, which parse() rejects')]
    public function testRefusesToFormatAYearOutsideTheRange(\DateTimeImmutable $dateTime): void
    {
        self::assertEquals(
            [new Violation('payload.committedAt', 'must have a UTC year from 0000 to 9999')],
            PayloadDateTime::yearViolations($dateTime, 'payload.committedAt'),
        );
        $this->expectException(\LogicException::class);

        PayloadDateTime::format($dateTime);
    }

    #[TestDox('Reports no year violation for null and for a UTC year inside 0000-9999')]
    public function testReportsNoYearViolationInsideTheRange(): void
    {
        foreach ([null, new \DateTimeImmutable('@-62167219200'), new \DateTimeImmutable('9999-12-31T23:59:59Z'), new \DateTimeImmutable('0000-01-01T00:00:00-01:00')] as $dateTime) {
            self::assertSame([], PayloadDateTime::yearViolations($dateTime, 'payload.committedAt'));
        }
    }

    #[TestDox('Parses the format it writes into a UTC date-time')]
    public function testParsesTheFormatItWrites(): void
    {
        $dateTime = PayloadDateTime::parse('2026-09-29T10:11:12Z', 'committedAt');

        self::assertSame('2026-09-29 10:11:12.000000 UTC', $dateTime->format('Y-m-d H:i:s.u e'));
        self::assertSame('2026-09-29T10:11:12Z', PayloadDateTime::format($dateTime));
        foreach (['0000-01-01T00:00:00Z', '9999-12-31T23:59:59Z'] as $edgeOfTheRange) {
            self::assertSame($edgeOfTheRange, PayloadDateTime::format(PayloadDateTime::parse($edgeOfTheRange, 'committedAt')));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherForms(): iterable
    {
        yield 'empty' => [''];
        yield 'February 30' => ['2026-02-30T10:11:12Z'];
        yield 'one-digit month' => ['2026-9-29T10:11:12Z'];
        yield 'trailing newline' => ["2026-09-29T10:11:12Z\n"];
    }

    #[DataProvider('otherForms')]
    #[TestDox('Rejects any other form, naming the field')]
    public function testRejectsAnyOtherForm(string $value): void
    {
        self::assertSame(['payload.committedAt'], self::violatedFields(static fn (): \DateTimeImmutable => PayloadDateTime::parse($value, 'payload.committedAt')));
    }
}
