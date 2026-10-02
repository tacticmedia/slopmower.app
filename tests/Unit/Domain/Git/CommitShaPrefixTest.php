<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Git;

use App\Domain\Git\CommitShaPrefix;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommitShaPrefixTest extends KernelTestCase
{
    use InvalidInputAssertions;

    private const string SHA256 = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f809179f8595a4c1e2b3d5f607182';

    #[TestDox('Accepts 7 to 64 lower-case hex characters')]
    public function testAccepts7To64Characters(): void
    {
        self::assertSame('79f8595', CommitShaPrefix::fromString('79f8595')->toString());
        self::assertSame(self::SHA256, CommitShaPrefix::fromString(self::SHA256)->toString());
    }

    #[TestDox('Rejects fewer than 7 or more than 64 characters and upper-case hex')]
    #[DataProvider('invalidPrefixes')]
    public function testRejectsInvalidPrefixes(string $value): void
    {
        self::assertInvalidInput(['commit'], static fn () => CommitShaPrefix::fromString($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPrefixes(): iterable
    {
        yield '6 characters' => ['79f859'];
        yield '65 characters' => [self::SHA256.'a'];
        yield 'upper-case hex' => ['79F8595'];
    }
}
