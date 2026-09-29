<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidTransition;
use App\Domain\Event\RequestClaimed;
use App\Domain\Event\RequestReleased;
use App\Domain\Identity\UserId;
use App\Domain\Request\Action;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\Release;
use App\Domain\Request\RequestDecider;
use App\Domain\Request\RequestStatus;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ClaimIdempotenceTest extends KernelTestCase
{
    use CatchesDomainErrors;

    #[TestDox('A repeated claim by the same agent gives no event')]
    public function testARepeatedClaimGivesNoEvent(): void
    {
        $agent = LifecycleFixtures::agent();
        $open = LifecycleFixtures::state(RequestStatus::Open);
        $claim = new Claim(LifecycleFixtures::requestId());

        $firstClaim = RequestDecider::decide($claim, [$open], LifecycleFixtures::openReview(), $agent);
        $claimed = LifecycleFixtures::applyDecided($open, $firstClaim, $agent);

        self::assertEquals([new RequestClaimed()], array_column($firstClaim, 'event'));
        self::assertSame([], RequestDecider::decide($claim, [$claimed], LifecycleFixtures::openReview(), $agent));
    }

    #[TestDox('Tokens with the same agent name count as the same agent')]
    public function testTokensWithTheSameAgentNameAreTheSameAgent(): void
    {
        $claimedByOldToken = LifecycleFixtures::state(RequestStatus::InProgress, claimedBy: Actor::agent(UserId::fromString(LifecycleFixtures::USER_ID), 'claude-code laptop'));
        $rotatedToken = Actor::agent(UserId::fromString(LifecycleFixtures::USER_ID), 'claude-code laptop');
        $requestId = LifecycleFixtures::requestId();

        self::assertSame([], RequestDecider::decide(new Claim($requestId), [$claimedByOldToken], LifecycleFixtures::openReview(), $rotatedToken));
        self::assertEquals(
            [new RequestReleased(null)],
            array_column(RequestDecider::decide(new Release($requestId), [$claimedByOldToken], LifecycleFixtures::openReview(), $rotatedToken), 'event'),
        );
    }

    #[TestDox('A claim by another agent gives invalid_transition with claimedBy')]
    public function testAClaimByAnotherAgentGivesInvalidTransition(): void
    {
        $holder = LifecycleFixtures::agent();
        $claimed = LifecycleFixtures::state(RequestStatus::InProgress, claimedBy: $holder);

        $error = self::domainError(InvalidTransition::class, static fn (): array => RequestDecider::decide(
            new Claim(LifecycleFixtures::requestId()),
            [$claimed],
            LifecycleFixtures::openReview(),
            LifecycleFixtures::otherAgent(),
        ));

        self::assertEquals([
            'requestId' => LifecycleFixtures::requestId(),
            'action' => Action::Claim,
            'status' => RequestStatus::InProgress,
            'claimedBy' => $holder,
            'allowedActions' => [Action::Reply],
        ], $error->details());
    }
}
