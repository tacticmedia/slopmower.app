<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Anchor;

use App\Domain\Anchor\ContentSnapshot;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContentSnapshotTest extends KernelTestCase
{
    use InvalidInputAssertions;

    private const string HASH = 'sha256:09938bff856dc45843179cd6426abee27580709e3ea2c414914ed5915d64787c';

    #[TestDox('Accepts 200 lines untruncated and 200 kept lines of a truncated region')]
    public function testAcceptsConsistentCounts(): void
    {
        $lines = array_fill(0, 200, 'x');

        self::assertFalse(ContentSnapshot::of($lines, false, 200, [], [], self::HASH)->truncated);
        self::assertSame(5000, ContentSnapshot::of($lines, true, 5000, ['a', 'b', 'c'], ['d'], self::HASH)->lineCount);
    }

    #[TestDox('Rejects counts that contradict lineCount, more than 3 context lines, and a malformed hash')]
    public function testRejectsInconsistentSnapshots(): void
    {
        self::assertInvalidInput(['snapshot.lines'], static fn () => ContentSnapshot::of(['a'], false, 2, [], [], self::HASH));
        self::assertInvalidInput(['snapshot.truncated'], static fn () => ContentSnapshot::of(['a'], true, 1, [], [], self::HASH));
        self::assertInvalidInput(['snapshot.lineCount'], static fn () => ContentSnapshot::of([], false, 0, [], [], self::HASH));
        self::assertInvalidInput(
            ['snapshot.before', 'snapshot.after'],
            static fn () => ContentSnapshot::of(['a'], false, 1, ['1', '2', '3', '4'], ['5', '6', '7', '8'], self::HASH),
        );
        self::assertInvalidInput(['snapshot.normalizedHash'], static fn () => ContentSnapshot::of(['a'], false, 1, [], [], 'sha1:abc'));
    }
}
