<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Event\RequestReleased;
use App\Domain\Request\Awaiting;
use App\Domain\Request\AwaitingPolicy;
use App\Domain\Request\Command\Release;
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReleaseRestoresStatusTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{RequestStatus}>
     */
    public static function statusesBeforeClaim(): iterable
    {
        yield 'open' => [RequestStatus::Open];
        yield 'reopened' => [RequestStatus::Reopened];
    }

    #[DataProvider('statusesBeforeClaim')]
    #[TestDox('Release restores open or reopened')]
    public function testReleaseRestoresTheStatusBeforeTheClaim(RequestStatus $statusBeforeClaim): void
    {
        $agent = LifecycleFixtures::agent();
        $claimed = LifecycleFixtures::state(RequestStatus::InProgress, statusBeforeClaim: $statusBeforeClaim);

        $decided = RequestDecider::decide(new Release(LifecycleFixtures::requestId(), 'Blocked by the tax rules.'), [$claimed], LifecycleFixtures::openReview(), $agent);
        $released = LifecycleFixtures::applyDecided($claimed, $decided, $agent);

        self::assertEquals([new RequestReleased('Blocked by the tax rules.')], array_column($decided, 'event'));
        self::assertSame(
            [$statusBeforeClaim, null, null, null],
            [$released->status, $released->statusBeforeClaim, $released->claimedBy, $released->claimedAt],
        );
    }

    #[TestDox('The user may release a claimed request')]
    public function testTheUserMayReleaseAClaimedRequest(): void
    {
        $user = LifecycleFixtures::user();
        $claimed = LifecycleFixtures::state(RequestStatus::InProgress, statusBeforeClaim: RequestStatus::Reopened);

        $decided = RequestDecider::decide(new Release(LifecycleFixtures::requestId()), [$claimed], LifecycleFixtures::openReview(), $user);
        $unassigned = LifecycleFixtures::applyDecided($claimed, $decided, $user);

        self::assertEquals([new RequestReleased(null)], array_column($decided, 'event'));
        self::assertSame([RequestStatus::Reopened, null, Awaiting::Agent], [$unassigned->status, $unassigned->claimedBy, AwaitingPolicy::of($unassigned)]);
    }
}
