<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Location;

use App\Domain\Anchor\LineRange;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Location\Location;
use App\Domain\Location\LocationStatus;
use App\Domain\Location\OrphanReason;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LocationTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('Gives exact and shifted confidence 1, and orphaned a reason without path, range, or confidence')]
    public function testSetsTheFieldsOfEachStatus(): void
    {
        $target = CommitSha::fromString('79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091');
        $path = RepoPath::fromString('src/Billing/InvoiceTotals.php');

        $shifted = Location::shifted($target, $path, LineRange::of(60, 66));
        $changed = Location::changed($target, $path, null, 0.75);
        $orphaned = Location::orphaned($target, OrphanReason::Ambiguous);

        self::assertSame(1.0, Location::exact($target, $path, null)->confidence);
        self::assertSame([LocationStatus::Shifted, 1.0, null], [$shifted->status, $shifted->confidence, $shifted->reason]);
        self::assertSame([LocationStatus::Changed, 0.75], [$changed->status, $changed->confidence]);
        self::assertSame([null, null, 0.0, OrphanReason::Ambiguous], [$orphaned->path, $orphaned->range, $orphaned->confidence, $orphaned->reason]);
    }

    #[TestDox('Rejects a changed confidence outside 0 to 1')]
    public function testRejectsAConfidenceOutsideTheUnitInterval(): void
    {
        $target = CommitSha::fromString('79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091');
        $path = RepoPath::fromString('src/Billing/InvoiceTotals.php');

        foreach ([-0.01, 1.01, \NAN] as $confidence) {
            self::assertInvalidInput(['confidence'], static fn () => Location::changed($target, $path, null, $confidence));
        }
    }
}
