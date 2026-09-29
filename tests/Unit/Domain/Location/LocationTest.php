<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Location;

use App\Domain\Anchor\LineRange;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Location\Location;
use App\Domain\Location\LocationStatus;
use App\Domain\Location\OrphanReason;
use App\Tests\Unit\Domain\SpecFiles;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LocationTest extends KernelTestCase
{
    use ViolatedFields;

    #[TestDox('Builds exact, shifted, changed, and orphaned locations')]
    public function testBuildsEachStatus(): void
    {
        $range = LineRange::of(38, 51);

        $exact = Location::exact(self::targetSha(), self::path(), null);
        $shifted = Location::shifted(self::targetSha(), RepoPath::fromString('src/Moved.php'), $range);
        $changed = Location::changed(self::targetSha(), self::path(), $range, 0.0);
        $orphaned = Location::orphaned(self::targetSha(), OrphanReason::Ambiguous);

        self::assertSame([LocationStatus::Exact, null, 1.0, null], [$exact->status, $exact->range, $exact->confidence, $exact->reason]);
        self::assertSame([LocationStatus::Shifted, 'src/Moved.php', $range, 1.0], [$shifted->status, $shifted->path?->toString(), $shifted->range, $shifted->confidence]);
        self::assertSame([LocationStatus::Changed, 0.0, null], [$changed->status, $changed->confidence, $changed->reason]);
        self::assertSame([LocationStatus::Orphaned, null, null, 0.0, OrphanReason::Ambiguous], [$orphaned->status, $orphaned->path, $orphaned->range, $orphaned->confidence, $orphaned->reason]);
        self::assertTrue($changed->equals(Location::fromParts(self::targetSha(), LocationStatus::Changed, self::path(), LineRange::of(38, 51), 0.0, null)));
        self::assertFalse($changed->equals(Location::changed(self::targetSha(), self::path(), $range, 0.5)));
    }

    #[DataProvider('fixtures')]
    #[TestDox('Rebuilds the location of the request fixture')]
    public function testRebuildsFixtureLocation(string $fixture): void
    {
        $fixtureLocation = SpecFiles::fixture($fixture)['location'];

        $location = Location::fromParts(
            CommitSha::fromString($fixtureLocation['targetSha']),
            LocationStatus::from($fixtureLocation['status']),
            RepoPath::fromString($fixtureLocation['path']),
            LineRange::of($fixtureLocation['range']['startLine'], $fixtureLocation['range']['endLine']),
            $fixtureLocation['confidence'],
            null,
        );

        self::assertSame(
            [$fixtureLocation['targetSha'], $fixtureLocation['status'], $fixtureLocation['path'], (float) $fixtureLocation['confidence']],
            [$location->targetSha->toString(), $location->status->value, $location->path?->toString(), $location->confidence],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        yield 'exact' => ['request.opened-range.json'];
        yield 'changed' => ['request.in-progress.json'];
    }

    /**
     * @param list<string> $expectedFields
     */
    #[DataProvider('invalidLocations')]
    #[TestDox('Enforces the path, range, confidence, and reason rules per status')]
    public function testEnforcesRulesPerStatus(LocationStatus $status, bool $withPath, ?LineRange $range, float $confidence, ?OrphanReason $reason, array $expectedFields): void
    {
        self::assertSame(
            $expectedFields,
            self::violatedFields(static fn (): Location => Location::fromParts(self::targetSha(), $status, $withPath ? self::path() : null, $range, $confidence, $reason)),
        );
    }

    /**
     * @return iterable<string, array{LocationStatus, bool, ?LineRange, float, ?OrphanReason, list<string>}>
     */
    public static function invalidLocations(): iterable
    {
        $range = LineRange::of(3, 4);

        yield 'orphaned with a path' => [LocationStatus::Orphaned, true, null, 0.0, OrphanReason::FileDeleted, ['location.path']];
        yield 'orphaned with a range' => [LocationStatus::Orphaned, false, $range, 0.0, OrphanReason::RegionDeleted, ['location.range']];
        yield 'orphaned without a reason' => [LocationStatus::Orphaned, false, null, 0.0, null, ['location.reason']];
        yield 'orphaned with confidence 0.5' => [LocationStatus::Orphaned, false, null, 0.5, OrphanReason::ContentNotFound, ['location.confidence']];
        yield 'exact without a path' => [LocationStatus::Exact, false, $range, 1.0, null, ['location.path']];
        yield 'exact with confidence 0.9' => [LocationStatus::Exact, true, $range, 0.9, null, ['location.confidence']];
        yield 'shifted with a reason' => [LocationStatus::Shifted, true, $range, 1.0, OrphanReason::Ambiguous, ['location.reason']];
        yield 'changed with confidence above 1' => [LocationStatus::Changed, true, $range, 1.5, null, ['location.confidence']];
        yield 'changed with negative confidence' => [LocationStatus::Changed, true, $range, -0.1, null, ['location.confidence']];
        yield 'changed with NaN confidence' => [LocationStatus::Changed, true, $range, \NAN, null, ['location.confidence']];
    }

    private static function targetSha(): CommitSha
    {
        return CommitSha::fromString('a1b2c3d4e5f60718293a4b5c6d7e8f9012345678');
    }

    private static function path(): RepoPath
    {
        return RepoPath::fromString('src/Billing/InvoiceTotals.php');
    }
}
