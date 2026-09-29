<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity;

use App\Domain\Error\InvalidInput;
use App\Domain\Identity\ApiTokenId;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\DraftId;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RepositoryId;
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
     * @var list<class-string<UlidId>>
     */
    private const array ID_CLASSES = [
        TenantId::class,
        UserId::class,
        RepositoryId::class,
        ReviewId::class,
        RequestId::class,
        CommentId::class,
        EventId::class,
        BatchId::class,
        ApiTokenId::class,
        DraftId::class,
    ];

    /**
     * @return iterable<string, array{class-string<UlidId>}>
     */
    public static function idClasses(): iterable
    {
        foreach (self::ID_CLASSES as $class) {
            yield self::shortName($class) => [$class];
        }
    }

    /**
     * @return iterable<string, array{class-string<UlidId>, string}>
     */
    public static function invalidStrings(): iterable
    {
        $head = substr(self::ULID, 0, 25);
        $invalidStrings = [
            'empty' => '',
            '25 characters' => $head,
            '27 characters' => self::ULID.'0',
            'first character 8' => '8'.substr(self::ULID, 1),
            'letter I' => $head.'I',
            'letter L' => $head.'L',
            'letter O' => $head.'O',
            'letter U' => $head.'U',
            'lower-case u' => strtolower($head).'u',
            'trailing newline' => self::ULID."\n",
            'leading space' => ' '.self::ULID,
            'NUL byte' => $head."\0",
            'RFC 4122 form' => '0199a6f0-6b1e-7c3a-9f2d-1a2b3c4d5e6f',
            'base58 form' => '1BKocMc5BnrVcuq2ti4Eqm',
            'binary form' => str_repeat("\x01", 16),
        ];
        foreach (self::ID_CLASSES as $class) {
            foreach ($invalidStrings as $label => $value) {
                yield self::shortName($class).', '.$label => [$class, $value];
            }
        }
    }

    /**
     * @return iterable<string, array{class-string<UlidId>, class-string<UlidId>}>
     */
    public static function pairsOfDifferentClasses(): iterable
    {
        foreach (self::ID_CLASSES as $class) {
            foreach (self::ID_CLASSES as $otherClass) {
                if ($class !== $otherClass) {
                    yield self::shortName($class).' and '.self::shortName($otherClass) => [$class, $otherClass];
                }
            }
        }
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

    /**
     * @param class-string<UlidId> $class
     */
    #[DataProvider('invalidStrings')]
    #[TestDox('Rejects an invalid string')]
    public function testRejectsAnInvalidString(string $class, string $value): void
    {
        $this->expectException(InvalidInput::class);

        $class::fromString($value);
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

    /**
     * @param class-string<UlidId> $class
     */
    private static function shortName(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }
}
