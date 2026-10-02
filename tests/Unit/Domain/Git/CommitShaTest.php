<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Git;

use App\Domain\Git\CommitSha;
use App\Domain\Git\CommitShaPrefix;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommitShaTest extends KernelTestCase
{
    use InvalidInputAssertions;

    private const string SHA1 = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091';
    private const string SHA256 = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f809179f8595a4c1e2b3d5f607182';

    #[TestDox('Accepts 40 and 64 hex characters')]
    public function testAccepts40And64HexCharacters(): void
    {
        self::assertSame(self::SHA1, CommitSha::fromString(self::SHA1)->toString());
        self::assertSame(self::SHA256, CommitSha::fromString(self::SHA256)->toString());
    }

    #[TestDox('Rejects upper-case hex')]
    public function testRejectsUpperCaseHex(): void
    {
        self::assertInvalidInput(['commitSha'], static fn () => CommitSha::fromString(strtoupper(self::SHA1)));
    }

    #[TestDox('Rejects a prefix that CommitShaPrefix accepts')]
    #[DataProvider('prefixes')]
    public function testRejectsAPrefix(string $prefix): void
    {
        CommitShaPrefix::fromString($prefix);

        self::assertInvalidInput(['commitSha'], static fn () => CommitSha::fromString($prefix));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function prefixes(): iterable
    {
        yield '39 characters' => [substr(self::SHA1, 0, 39)];
        yield '63 characters' => [substr(self::SHA256, 0, 63)];
    }

    #[TestDox('Rejects a full SHA with a trailing newline')]
    public function testRejectsATrailingNewline(): void
    {
        self::assertInvalidInput(['at'], static fn () => CommitSha::fromString(self::SHA1."\n", 'at'));
    }
}
