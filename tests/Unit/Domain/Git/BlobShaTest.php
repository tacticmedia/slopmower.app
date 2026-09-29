<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Git;

use App\Domain\Error\InvalidInput;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitShaPrefix;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BlobShaTest extends KernelTestCase
{
    use ViolatedFields;

    private const string SHA1 = 'd0b05a7b5719fe83d400048e5ebe6009b273740d';
    private const string SHA256 = '26e79ecbf354413f5dda5bbccb61f907174e1ffd61a78104cd8b8f3e98bc68dd';

    /**
     * @return iterable<string, array{string}>
     */
    public static function fullShas(): iterable
    {
        yield 'SHA-1' => [self::SHA1];
        yield 'SHA-256' => [self::SHA256];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function upperCaseShas(): iterable
    {
        yield 'SHA-1, one upper-case letter' => [substr_replace(self::SHA1, 'D', 0, 1)];
        yield 'SHA-256, one upper-case letter' => [substr_replace(self::SHA256, 'D', 63, 1)];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function prefixes(): iterable
    {
        foreach ([12, 63] as $length) {
            yield $length.' characters' => [substr(self::SHA256, 0, $length)];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherInvalidStrings(): iterable
    {
        yield '65 characters' => [self::SHA256.'0'];
        yield 'non-hex letter' => [substr_replace(self::SHA1, 'g', 0, 1)];
        yield 'trailing newline' => [self::SHA1."\n"];
        yield 'leading space' => [' '.self::SHA1];
    }

    #[DataProvider('fullShas')]
    #[TestDox('Accepts 40 and 64 hex characters')]
    public function testAccepts40And64HexCharacters(string $value): void
    {
        $sha = BlobSha::fromString($value);

        self::assertSame($value, $sha->toString());
        self::assertSame($value, (string) $sha);
        self::assertTrue($sha->equals(BlobSha::fromString($value)));
    }

    #[DataProvider('upperCaseShas')]
    #[TestDox('Rejects upper-case hex')]
    public function testRejectsUpperCaseHex(string $value): void
    {
        $this->expectException(InvalidInput::class);

        BlobSha::fromString($value);
    }

    #[DataProvider('prefixes')]
    #[TestDox('Rejects a prefix that CommitShaPrefix accepts')]
    public function testRejectsAPrefixThatCommitShaPrefixAccepts(string $value): void
    {
        self::assertSame($value, CommitShaPrefix::fromString($value)->toString());

        $this->expectException(InvalidInput::class);

        BlobSha::fromString($value);
    }

    #[DataProvider('otherInvalidStrings')]
    #[TestDox('Rejects other lengths, non-hex characters, and surrounding whitespace')]
    public function testRejectsOtherInvalidStrings(string $value): void
    {
        $this->expectException(InvalidInput::class);

        BlobSha::fromString($value);
    }

    #[TestDox('Equals only the same SHA')]
    public function testEqualsOnlyTheSameSha(): void
    {
        self::assertFalse(BlobSha::fromString(self::SHA1)->equals(BlobSha::fromString(substr(self::SHA256, 0, 40))));
    }

    #[TestDox('Names blobSha or the given field in the violation')]
    public function testNamesBlobShaOrTheGivenFieldInTheViolation(): void
    {
        self::assertSame(['blobSha'], self::violatedFields(static fn (): BlobSha => BlobSha::fromString('x')));
        self::assertSame(['anchor.blobSha'], self::violatedFields(static fn (): BlobSha => BlobSha::fromString('x', 'anchor.blobSha')));
    }
}
