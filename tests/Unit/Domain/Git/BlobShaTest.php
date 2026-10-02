<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Git;

use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitShaPrefix;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BlobShaTest extends KernelTestCase
{
    use InvalidInputAssertions;

    private const string SHA1 = '3b18e512dba79e4c8300dd08aeb37f8e728b8dad';
    private const string SHA256 = '3b18e512dba79e4c8300dd08aeb37f8e728b8dad3b18e512dba79e4c8300dd08';

    #[TestDox('Accepts 40 and 64 hex characters')]
    public function testAccepts40And64HexCharacters(): void
    {
        self::assertSame(self::SHA1, BlobSha::fromString(self::SHA1)->toString());
        self::assertSame(self::SHA256, BlobSha::fromString(self::SHA256)->toString());
    }

    #[TestDox('Rejects upper-case hex')]
    public function testRejectsUpperCaseHex(): void
    {
        self::assertInvalidInput(['blobSha'], static fn () => BlobSha::fromString(strtoupper(self::SHA1)));
    }

    #[TestDox('Rejects a prefix that CommitShaPrefix accepts')]
    public function testRejectsAPrefix(): void
    {
        $prefix = substr(self::SHA1, 0, 12);
        CommitShaPrefix::fromString($prefix);

        self::assertInvalidInput(['blobSha'], static fn () => BlobSha::fromString($prefix));
    }
}
