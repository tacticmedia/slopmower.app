<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity;

use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IdentifierTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('Round-trips its string form')]
    public function testRoundTripsItsStringForm(): void
    {
        $id = RequestId::generate();

        $parsed = RequestId::fromString($id->toString());

        self::assertTrue($parsed->equals($id));
        self::assertSame($id->toString(), (string) $parsed);
    }

    #[TestDox('Prints 26 upper-case Crockford characters and accepts lower-case input')]
    public function testPrintsUpperCaseAndAcceptsLowerCase(): void
    {
        $lowerCase = '01k6b3a1b2c3d4e5f6g7h8j9km';

        $id = ReviewId::fromString($lowerCase);

        self::assertSame('01K6B3A1B2C3D4E5F6G7H8J9KM', $id->toString());
        self::assertTrue($id->equals(ReviewId::fromString('01K6B3A1B2C3D4E5F6G7H8J9KM')));
        self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', ReviewId::generate()->toString());
    }

    #[TestDox('Rejects an invalid string')]
    #[DataProvider('invalidStrings')]
    public function testRejectsAnInvalidString(string $value): void
    {
        self::assertInvalidInput(['tenantId'], static fn () => TenantId::fromString($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStrings(): iterable
    {
        yield 'empty' => [''];
        yield 'RFC 4122 form of a ULID' => ['0199a3b2-1c4e-7d5f-8a6b-3c2d1e0f9a8b'];
    }

    #[TestDox('Never equals an ID of another type with the same value')]
    public function testNeverEqualsAnIdOfAnotherType(): void
    {
        $value = '01K6AZZ9Y8X7W6V5T4S3R2Q1P0';

        self::assertFalse(TenantId::fromString($value)->equals(UserId::fromString($value)));
        self::assertTrue(TenantId::fromString($value)->equals(TenantId::fromString($value)));
    }
}
