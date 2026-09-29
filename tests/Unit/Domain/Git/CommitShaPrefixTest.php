<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Git;

use App\Domain\Error\InvalidInput;
use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommitShaPrefixTest extends KernelTestCase
{
    use ViolatedFields;

    private const string SHA1 = 'd0b05a7b5719fe83d400048e5ebe6009b273740d';
    private const string SHA256 = '26e79ecbf354413f5dda5bbccb61f907174e1ffd61a78104cd8b8f3e98bc68dd';

    /**
     * @return iterable<string, array{string}>
     */
    public static function validPrefixes(): iterable
    {
        foreach ([7, 64] as $length) {
            yield $length.' characters' => [substr(self::SHA256, 0, $length)];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPrefixes(): iterable
    {
        yield '6 characters' => [substr(self::SHA1, 0, 6)];
        yield '65 characters' => [self::SHA256.'0'];
        yield 'upper-case hex' => [strtoupper(substr(self::SHA1, 0, 7))];
        yield 'trailing newline' => [substr(self::SHA1, 0, 7)."\n"];
        yield 'leading dash' => ['-'.substr(self::SHA1, 0, 7)];
    }

    #[DataProvider('validPrefixes')]
    #[TestDox('Accepts 7 to 64 lower-case hex characters')]
    public function testAccepts7To64LowerCaseHexCharacters(string $value): void
    {
        $prefix = CommitShaPrefix::fromString($value);

        self::assertSame($value, $prefix->toString());
        self::assertSame($value, (string) $prefix);
        self::assertTrue($prefix->equals(CommitShaPrefix::fromString($value)));
    }

    #[DataProvider('invalidPrefixes')]
    #[TestDox('Rejects fewer than 7 or more than 64 characters, upper-case hex, and other characters')]
    public function testRejectsInvalidPrefixes(string $value): void
    {
        $this->expectException(InvalidInput::class);

        CommitShaPrefix::fromString($value);
    }

    #[TestDox('Matches only a commit SHA that starts with it')]
    public function testMatchesOnlyACommitShaThatStartsWithIt(): void
    {
        $sha1 = CommitSha::fromString(self::SHA1);
        $sha256 = CommitSha::fromString(self::SHA256);

        self::assertTrue(CommitShaPrefix::fromString(substr(self::SHA1, 0, 7))->matches($sha1));
        self::assertFalse(CommitShaPrefix::fromString(substr(self::SHA1, 0, 7))->matches($sha256));
        self::assertTrue(CommitShaPrefix::fromString(self::SHA1)->matches($sha1));
        self::assertTrue(CommitShaPrefix::fromString(substr(self::SHA256, 0, 41))->matches($sha256));
        self::assertTrue(CommitShaPrefix::fromString(self::SHA256)->matches($sha256));
        self::assertFalse(CommitShaPrefix::fromString(self::SHA1.'0')->matches($sha1));
        self::assertFalse(CommitShaPrefix::fromString(substr(self::SHA256, 1, 7))->matches($sha256));
    }

    #[TestDox('Equals only the same characters')]
    public function testEqualsOnlyTheSameCharacters(): void
    {
        self::assertFalse(CommitShaPrefix::fromString(substr(self::SHA1, 0, 7))->equals(CommitShaPrefix::fromString(substr(self::SHA1, 0, 8))));
    }

    #[TestDox('Names commit or the given field in the violation')]
    public function testNamesCommitOrTheGivenFieldInTheViolation(): void
    {
        self::assertSame(['commit'], self::violatedFields(static fn (): CommitShaPrefix => CommitShaPrefix::fromString('x')));
        self::assertSame(['at'], self::violatedFields(static fn (): CommitShaPrefix => CommitShaPrefix::fromString('x', 'at')));
    }
}
