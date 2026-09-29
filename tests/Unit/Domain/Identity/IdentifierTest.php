<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity;

use App\Domain\Error\InvalidInput;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Identity\UlidId;
use App\Domain\Identity\UserId;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Ulid;

final class IdentifierTest extends KernelTestCase
{
    use ViolatedFields;

    private const string ULID = '01K6B3P1Z8Y7X6W5V4T3S2R1Q0';

    /**
     * @return iterable<string, array{class-string<UlidId>}>
     */
    public static function idClasses(): iterable
    {
        yield 'ReviewId' => [ReviewId::class];
        yield 'TenantId' => [TenantId::class];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStrings(): iterable
    {
        $head = substr(self::ULID, 0, 25);
        yield '25 characters' => [$head];
        yield '27 characters' => [self::ULID.'0'];
        yield 'first character 8' => ['8'.substr(self::ULID, 1)];
        yield 'letter I' => [$head.'I'];
        yield 'lower-case u' => [strtolower($head).'u'];
        yield 'trailing newline' => [self::ULID."\n"];
        yield 'leading space' => [' '.self::ULID];
    }

    /**
     * @return iterable<string, array{class-string<UlidId>, class-string<UlidId>}>
     */
    public static function pairsOfDifferentClasses(): iterable
    {
        yield 'ReviewId and RequestId' => [ReviewId::class, RequestId::class];
        yield 'TenantId and UserId' => [TenantId::class, UserId::class];
    }

    /**
     * @param class-string<UlidId> $class
     */
    #[DataProvider('idClasses')]
    #[TestDox('Round-trips its string form')]
    public function testRoundTripsItsStringForm(string $class): void
    {
        $generated = $class::generate();
        $parsed = $class::fromString($generated->toString());

        self::assertInstanceOf($class, $parsed);
        self::assertTrue($parsed->equals($generated));
        self::assertSame($generated->toString(), (string) $parsed);
        self::assertTrue($class::fromUlid($generated->toUlid())->equals($generated));
        self::assertSame(self::ULID, $class::fromString(self::ULID)->toString());
    }

    /**
     * @param class-string<UlidId> $class
     */
    #[DataProvider('idClasses')]
    #[TestDox('Prints 26 upper-case Crockford characters and accepts lower-case input')]
    public function testPrintsUpperCaseAndAcceptsLowerCaseInput(string $class): void
    {
        $fromLowerCase = $class::fromString(strtolower(self::ULID));

        self::assertSame(self::ULID, $fromLowerCase->toString());
        self::assertSame(self::ULID, $class::fromString('01k6B3p1Z8y7X6w5V4t3S2r1Q0')->toString());
        self::assertTrue($fromLowerCase->equals($class::fromString(self::ULID)));
        self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $class::generate()->toString());
    }

    #[DataProvider('invalidStrings')]
    #[TestDox('Rejects an invalid string')]
    public function testRejectsAnInvalidString(string $value): void
    {
        $this->expectException(InvalidInput::class);

        ReviewId::fromString($value);
    }

    /**
     * @param class-string<UlidId> $class
     * @param class-string<UlidId> $otherClass
     */
    #[DataProvider('pairsOfDifferentClasses')]
    #[TestDox('Never equals an ID of another type with the same value')]
    public function testNeverEqualsAnIdOfAnotherTypeWithTheSameValue(string $class, string $otherClass): void
    {
        $ulid = new Ulid(self::ULID);

        self::assertFalse($class::fromUlid($ulid)->equals($otherClass::fromUlid($ulid)));
        self::assertTrue($class::fromUlid($ulid)->equals($class::fromString(self::ULID)));
    }

    #[TestDox('Accepts the lowest and the highest ULID')]
    public function testAcceptsTheLowestAndTheHighestUlid(): void
    {
        self::assertSame('00000000000000000000000000', ReviewId::fromString('00000000000000000000000000')->toString());
        self::assertSame('7ZZZZZZZZZZZZZZZZZZZZZZZZZ', ReviewId::fromString('7zzzzzzzzzzzzzzzzzzzzzzzzz')->toString());
    }

    #[TestDox('Names id or the given field in the violation')]
    public function testNamesIdOrTheGivenFieldInTheViolation(): void
    {
        self::assertSame(['id'], self::violatedFields(static fn (): ReviewId => ReviewId::fromString('x')));
        self::assertSame(['reviewId'], self::violatedFields(static fn (): ReviewId => ReviewId::fromString('x', 'reviewId')));
    }
}
